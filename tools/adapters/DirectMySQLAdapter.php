<?php
/**
 * Adapter za neposredno MySQL bazo (testna in MVP produkcijska raba).
 *
 * Vse poizvedbe gredo prek pripravljenih stavkov (PDO prepared statements),
 * zato vhod stranke nikoli ne pride v SQL kot besedilo.
 */
final class DirectMySQLAdapter implements AdapterInterface
{
    /** @var PDO|null */
    private $pdo = null;
    /** @var array */
    private $config;
    /** @var string */
    private $prefix;

    /**
     * @param array $config host, name, user, pass, charset, prefix
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Imena tabel ne morejo skozi pripravljen stavek, zato se predpona
        // zlepi v SQL. Iz konfiguracije sicer ne pride uporabnikov vnos, a
        // znak zunaj tega nabora bi vseeno pomenil zlomljen ali vrinjen SQL.
        $prefix = (string) ($config['prefix'] ?? '');
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix)) {
            throw new AdapterException('DB_PREFIX sme vsebovati samo črke, številke in podčrtaj.');
        }
        $this->prefix = $prefix;
    }

    /** Ime tabele s predpono, npr. "products" -> "ai_products". */
    private function table(string $name): string
    {
        return $this->prefix . $name;
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['name'],
            $this->config['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Sporočilo PDO vsebuje uporabniško ime in gostitelja — ne sme ven.
            error_log('DirectMySQLAdapter: povezava neuspešna: ' . $e->getMessage());
            throw new AdapterException('Povezava s podatkovno bazo ni uspela.');
        }

        return $this->pdo;
    }

    public function searchProducts(string $query, ?string $category = null, int $limit = 10): array
    {
        $tokens = $this->tokenize($query);
        $limit  = max(1, min($limit, 25));

        // Najprej strogo: vsak iskalni izraz se mora pojaviti (AND).
        $rows = $this->runProductSearch($tokens, $category, $limit, 'AND');
        if (!$rows && count($tokens) > 1) {
            // Če ni zadetka, popustimo — stranka je morda dodala odvečne besede.
            $rows = $this->runProductSearch($tokens, $category, $limit, 'OR');
        }

        return array_map([$this, 'mapProduct'], $rows);
    }

    private function runProductSearch(array $tokens, ?string $category, int $limit, string $glue): array
    {
        $where  = ['active = 1'];
        $params = [];

        if ($tokens) {
            // Vsak placeholder sme v pripravljenem stavku (native prepare) nastopiti
            // samo enkrat — zato tri ločene vezave namesto enega :tN, ponovljenega
            // trikrat, kar PDO MySQL zavrne z "Invalid parameter number".
            $clauses = [];
            foreach ($tokens as $i => $token) {
                $keyName = ':t' . $i . 'n';
                $keyDesc = ':t' . $i . 'd';
                $keyCat  = ':t' . $i . 'c';
                $clauses[] = "(name LIKE {$keyName} OR description LIKE {$keyDesc} OR category LIKE {$keyCat})";

                $value               = '%' . $token . '%';
                $params[$keyName]    = $value;
                $params[$keyDesc]    = $value;
                $params[$keyCat]     = $value;
            }
            $where[] = '(' . implode(" {$glue} ", $clauses) . ')';
        }

        if ($category !== null && $category !== '') {
            $where[]         = 'category = :category';
            $params[':category'] = $category;
        }

        // Storitve brez objavljene cene (NULL) naj pridejo na konec, ne na zacetek.
        $sql = 'SELECT id, name, category, unit, price_per_unit, price_from, stock_quantity, description
                FROM ' . $this->table('products') . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY (stock_quantity > 0) DESC, (price_per_unit IS NULL) ASC, price_per_unit ASC
                LIMIT ' . (int) $limit;

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::searchProducts: ' . $e->getMessage());
            throw new AdapterException('Iskanje izdelkov ni uspelo.');
        }
    }

    public function getProductById(int $id): ?array
    {
        try {
            $stmt = $this->pdo()->prepare(
                'SELECT id, name, category, unit, price_per_unit, price_from, stock_quantity, description
                 FROM ' . $this->table('products') . ' WHERE id = :id AND active = 1'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::getProductById: ' . $e->getMessage());
            throw new AdapterException('Branje izdelka ni uspelo.');
        }

        return $row ? $this->mapProduct($row) : null;
    }

    public function findOrderById(int $orderId): ?array
    {
        try {
            $stmt = $this->pdo()->prepare(
                'SELECT o.id, o.status, o.order_date, o.delivery_date, o.quantity, o.note,
                        p.name AS product_name, p.unit AS product_unit, p.price_per_unit,
                        c.id AS customer_id, c.name AS customer_name,
                        c.phone AS customer_phone, c.email AS customer_email
                 FROM ' . $this->table('orders') . ' o
                 JOIN ' . $this->table('products') . '  p ON p.id = o.product_id
                 JOIN ' . $this->table('customers') . ' c ON c.id = o.customer_id
                 WHERE o.id = :id'
            );
            $stmt->execute([':id' => $orderId]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::findOrderById: ' . $e->getMessage());
            throw new AdapterException('Branje naročila ni uspelo.');
        }

        if (!$row) {
            return null;
        }

        return [
            'id'             => (int) $row['id'],
            'status'         => (string) $row['status'],
            'order_date'     => $row['order_date'],
            'delivery_date'  => $row['delivery_date'],
            'quantity'       => (float) $row['quantity'],
            'note'           => $row['note'],
            'product_name'   => $row['product_name'],
            'product_unit'   => $row['product_unit'],
            'price_per_unit' => (float) $row['price_per_unit'],
            'customer_id'    => (int) $row['customer_id'],
            'customer_name'  => $row['customer_name'],
            'customer_phone' => $row['customer_phone'],
            'customer_email' => $row['customer_email'],
        ];
    }

    public function getBusinessHours(): array
    {
        try {
            $stmt = $this->pdo()->query(
                'SELECT day_of_week, opens_at, closes_at, closed FROM ' . $this->table('business_hours') . ' ORDER BY day_of_week'
            );
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::getBusinessHours: ' . $e->getMessage());
            throw new AdapterException('Branje delovnega časa ni uspelo.');
        }

        $hours = [];
        foreach ($rows as $row) {
            $day = (int) $row['day_of_week'];
            $hours[$day] = [
                'day_of_week' => $day,
                'opens_at'    => $row['opens_at'] ? substr($row['opens_at'], 0, 5) : null,
                'closes_at'   => $row['closes_at'] ? substr($row['closes_at'], 0, 5) : null,
                'closed'      => (bool) $row['closed'],
            ];
        }

        return $hours;
    }

    public function createInquiry(array $inquiry): int
    {
        try {
            $stmt = $this->pdo()->prepare(
                'INSERT INTO ' . $this->table('inquiries') . ' (name, phone, email, product, quantity, note, source, status, created_at)
                 VALUES (:name, :phone, :email, :product, :quantity, :note, :source, \'new\', NOW())'
            );
            $stmt->execute([
                ':name'     => $inquiry['name'],
                ':phone'    => $inquiry['phone'],
                ':email'    => $inquiry['email']    ?? null,
                ':product'  => $inquiry['product']  ?? null,
                ':quantity' => $inquiry['quantity'] ?? null,
                ':note'     => $inquiry['note']     ?? null,
                ':source'   => $inquiry['source']   ?? 'chat',
            ]);

            return (int) $this->pdo()->lastInsertId();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::createInquiry: ' . $e->getMessage());
            throw new AdapterException('Povpraševanja ni bilo mogoče shraniti.');
        }
    }

    /** Stanja, ki jih dovoljuje shema. Vrednost od drugod se zavrne. */
    private const STANJA = ['new', 'handled', 'discarded'];

    public function listInquiries(array $filter = []): array
    {
        $where  = [];
        $params = [];

        $status = $filter['status'] ?? '';
        if ($status !== '' && in_array($status, self::STANJA, true)) {
            $where[]           = 'status = :status';
            $params[':status'] = $status;
        }

        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            // Vsako polje dobi svoj placeholder — isti se v pripravljenem
            // stavku ne sme ponoviti.
            $where[] = '(name LIKE :s1 OR phone LIKE :s2 OR email LIKE :s3 OR product LIKE :s4 OR note LIKE :s5)';
            foreach (['s1', 's2', 's3', 's4', 's5'] as $k) {
                $params[':' . $k] = '%' . $search . '%';
            }
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit    = max(1, min((int) ($filter['limit'] ?? 50), 200));
        $offset   = max(0, (int) ($filter['offset'] ?? 0));

        try {
            $stmt = $this->pdo()->prepare(
                'SELECT COUNT(*) FROM ' . $this->table('inquiries') . $sqlWhere
            );
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();

            $stmt = $this->pdo()->prepare(
                'SELECT id, created_at, name, phone, email, product, quantity, note, source, status
                 FROM ' . $this->table('inquiries') . $sqlWhere . '
                 ORDER BY id DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $stmt->execute($params);

            return ['items' => $stmt->fetchAll(), 'total' => $total];
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::listInquiries: ' . $e->getMessage());
            throw new AdapterException('Branje povpraševanj ni uspelo.');
        }
    }

    public function updateInquiryStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STANJA, true)) {
            throw new AdapterException('Neveljavno stanje povpraševanja.');
        }

        try {
            $stmt = $this->pdo()->prepare(
                'UPDATE ' . $this->table('inquiries') . ' SET status = :status WHERE id = :id'
            );
            $stmt->execute([':status' => $status, ':id' => $id]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::updateInquiryStatus: ' . $e->getMessage());
            throw new AdapterException('Sprememba stanja ni uspela.');
        }
    }

    public function listProducts(): array
    {
        try {
            $stmt = $this->pdo()->query(
                'SELECT id, name, category, unit, price_per_unit, price_from, stock_quantity,
                        description, active
                 FROM ' . $this->table('products') . '
                 ORDER BY active DESC, category, name'
            );
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::listProducts: ' . $e->getMessage());
            throw new AdapterException('Branje storitev ni uspelo.');
        }
    }

    public function saveProduct(array $product): int
    {
        // Cena sme biti prazna — takrat asistent pove "cena po dogovoru".
        // Prazen niz ni isto kot nič: (float)'' bi dal 0 in asistent bi
        // stranki povedal, da je storitev zastonj.
        $cena = $product['price_per_unit'];
        $cena = ($cena === null || $cena === '') ? null : (float) $cena;

        $polja = [
            ':name'        => trim((string) $product['name']),
            ':category'    => (string) $product['category'],
            ':unit'        => (string) $product['unit'],
            ':price'       => $cena,
            ':price_from'  => !empty($product['price_from']) ? 1 : 0,
            ':description' => ($product['description'] ?? '') !== '' ? (string) $product['description'] : null,
            ':active'      => !empty($product['active']) ? 1 : 0,
        ];

        try {
            $id = (int) ($product['id'] ?? 0);

            if ($id > 0) {
                $stmt = $this->pdo()->prepare(
                    'UPDATE ' . $this->table('products') . '
                     SET name = :name, category = :category, unit = :unit,
                         price_per_unit = :price, price_from = :price_from,
                         description = :description, active = :active
                     WHERE id = :id'
                );
                $stmt->execute($polja + [':id' => $id]);
                return $id;
            }

            $stmt = $this->pdo()->prepare(
                'INSERT INTO ' . $this->table('products') . '
                 (name, category, unit, price_per_unit, price_from, description, active, stock_quantity)
                 VALUES (:name, :category, :unit, :price, :price_from, :description, :active, 1)'
            );
            $stmt->execute($polja);

            return (int) $this->pdo()->lastInsertId();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::saveProduct: ' . $e->getMessage());
            throw new AdapterException('Shranjevanje storitve ni uspelo.');
        }
    }

    // ----------------------------------------------------------------

    private function mapProduct(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'name'           => $row['name'],
            'category'       => $row['category'],
            'unit'           => $row['unit'],
            // NULL ostane NULL — pomeni "cena po dogovoru". Pretvorba v (float)
            // bi jo spremenila v 0 in asistent bi stranki povedal, da je zastonj.
            'price_per_unit' => $row['price_per_unit'] === null ? null : (float) $row['price_per_unit'],
            'price_from'     => (bool) ($row['price_from'] ?? false),
            'stock_quantity' => (float) $row['stock_quantity'],
            'description'    => $row['description'],
        ];
    }

    /**
     * Razbije iskalni niz na izraze in daljše skrajša na koren.
     *
     * Slovenščina sklanja: "bukovih peletov" mora najti "Peleti A1 bukev".
     * Skrajšanje na prvih 5 znakov ("bukovih" -> "bukov", "peletov" -> "pelet")
     * pokrije večino sklonov brez pravega lematizatorja.
     *
     * @return string[]
     */
    private function tokenize(string $query): array
    {
        $query  = mb_strtolower(trim($query));
        $parts  = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = mb_strlen($part) > 5 ? mb_substr($part, 0, 5) : $part;
            if (count($tokens) >= 6) {
                break;
            }
        }

        return array_values(array_unique($tokens));
    }

    public function searchKnowledge(string $query, int $limit = 3): array
    {
        // Locila se drzijo besede: "organizacije?" se z "organizacije" ne ujema.
        $besede = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Mašilne besede se ujamejo z vsem in prinesejo napačen zadetek.
        // "Ali delate tudi za društva" se je ujelo z zapisom "Ali delate tudi za
        // stranke izven vaše okolice", ki govori o geografiji — asistent je nato
        // trdil, da delajo za društva, česar ni vedel nihče.
        $masila = [
            'ali', 'kaj', 'kako', 'kje', 'kdaj', 'kdo', 'zakaj', 'koliko',
            'tudi', 'samo', 'lahko', 'sem', 'ste', 'sta', 'smo', 'mi', 'vi',
            'vas', 'nas', 'vam', 'nam', 'moj', 'vaš', 'vas', 'pri', 'zelo',
            'bi', 'je', 'so', 'in', 'za', 'na', 'se', 'pa', 'ki', 'to', 'ta',
            'me', 'mu', 'ga', 'jo', 'jih', 'bil', 'bilo', 'bila', 'bom', 'bo',
        ];

        $besede = array_filter(
            $besede,
            static fn($b) => mb_strlen($b) >= 4 && !in_array($b, $masila, true)
        );
        $besede = array_slice(array_values(array_unique($besede)), 0, 6);

        if (!$besede) {
            // Vprašanje iz samih mašil ni vprašanje. Prazen izid je pravilnejši
            // od naključnega zadetka.
            return [];
        }

        // LIKE in ne MATCH: polnotekstovni indeks slovenscine ne pregiba, zato
        // "drustva" ne najde "drustvo". LIKE z delnim ujemanjem to premosti,
        // pri nekaj sto zapisih pa je hitrost brez pomena.
        $pogoji = [];
        $parametri = [];
        foreach (array_values($besede) as $i => $beseda) {
            // Vsaka pojavitev rabi svoj imenovani parameter: isti dvakrat
            // pomeni SQLSTATE[HY093] pri pravih pripravljenih stavkih.
            $kv = ':v' . $i . 'q';
            $ka = ':v' . $i . 'a';
            $kk = ':v' . $i . 'k';
            $pogoji[] = "(question LIKE {$kv} OR answer LIKE {$ka} OR keywords LIKE {$kk})";
            $vzorec = '%' . $beseda . '%';
            $parametri[$kv] = $vzorec;
            $parametri[$ka] = $vzorec;
            $parametri[$kk] = $vzorec;
        }

        $sql = 'SELECT id, question, answer, keywords
                FROM ' . $this->table('knowledge') . '
                WHERE active = 1 AND (' . implode(' OR ', $pogoji) . ')
                LIMIT ' . max(1, min($limit, 10));

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($parametri);
            $vrstice = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::searchKnowledge: ' . $e->getMessage());
            throw new AdapterException('Iskanje po bazi znanja ni uspelo.');
        }

        // Ena sama ujemajoca beseda je prevec ohlapna. "Ali delate tudi za
        // drustva" se je z zapisom "Ali delate tudi za stranke izven vase
        // okolice" ujelo samo na besedi "delate" - in asistent je iz zapisa o
        // geografiji sklepal o drustvih. Ko je besed vec, jih mora zadeti vsaj
        // dve, sicer zadetek ni o isti temi.
        $najmanj = count($besede) > 1 ? 2 : 1;

        $ocenjeni = [];
        foreach ($vrstice as $vrstica) {
            $seno = mb_strtolower(
                $vrstica['question'] . ' ' . $vrstica['answer'] . ' ' . ($vrstica['keywords'] ?? '')
            );
            $tocke = 0;
            foreach ($besede as $beseda) {
                if (mb_strpos($seno, $beseda) !== false) {
                    $tocke++;
                }
            }
            if ($tocke >= $najmanj) {
                $ocenjeni[] = ['tocke' => $tocke, 'vrstica' => $vrstica];
            }
        }

        usort($ocenjeni, static fn($a, $b) => $b['tocke'] <=> $a['tocke']);

        return array_map(
            static fn(array $z) => ['question' => $z['vrstica']['question'], 'answer' => $z['vrstica']['answer']],
            array_slice($ocenjeni, 0, max(1, min($limit, 10)))
        );
    }

    public function listKnowledge(): array
    {
        try {
            $stmt = $this->pdo()->query(
                'SELECT id, question, answer, keywords, active, updated_at
                 FROM ' . $this->table('knowledge') . '
                 ORDER BY active DESC, updated_at DESC'
            );
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::listKnowledge: ' . $e->getMessage());
            throw new AdapterException('Branje baze znanja ni uspelo.');
        }
    }

    public function saveKnowledge(array $entry): int
    {
        $polja = [
            'question' => (string) ($entry['question'] ?? ''),
            'answer'   => (string) ($entry['answer'] ?? ''),
            'keywords' => ($entry['keywords'] ?? '') === '' ? null : (string) $entry['keywords'],
            'active'   => !empty($entry['active']) ? 1 : 0,
        ];

        try {
            $id = (int) ($entry['id'] ?? 0);
            if ($id > 0) {
                $stmt = $this->pdo()->prepare(
                    'UPDATE ' . $this->table('knowledge') . '
                     SET question = :question, answer = :answer, keywords = :keywords, active = :active
                     WHERE id = :id'
                );
                $stmt->execute($polja + ['id' => $id]);
                return $id;
            }

            $stmt = $this->pdo()->prepare(
                'INSERT INTO ' . $this->table('knowledge') . ' (question, answer, keywords, active)
                 VALUES (:question, :answer, :keywords, :active)'
            );
            $stmt->execute($polja);
            return (int) $this->pdo()->lastInsertId();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::saveKnowledge: ' . $e->getMessage());
            throw new AdapterException('Shranjevanje zapisa ni uspelo.');
        }
    }

    public function deleteKnowledge(int $id): bool
    {
        try {
            $stmt = $this->pdo()->prepare('DELETE FROM ' . $this->table('knowledge') . ' WHERE id = :id');
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::deleteKnowledge: ' . $e->getMessage());
            throw new AdapterException('Brisanje zapisa ni uspelo.');
        }
    }

    public function findFreeSlots(DateTimeImmutable $from, int $days, int $durationMin, int $limit = 6): array
    {
        $delovnik = $this->getBusinessHours();
        if (!$delovnik) {
            return [];
        }

        $days     = max(1, min($days, 30));
        $durationMin = max(10, min($durationMin, 240));
        $do       = $from->modify('+' . $days . ' days');
        $zasedeni = $this->zasedeniTermini($from, $do);

        // Termina se ne ponudi za cez nekaj minut: stranka ga ne more ujeti in
        // podjetje se nanj ne more pripraviti.
        $najprej = $from->modify('+' . (defined('APPOINTMENT_LEAD_MIN') ? (int) APPOINTMENT_LEAD_MIN : 120) . ' minutes');

        $prosti = [];
        $dan = $from->setTime(0, 0);

        for ($i = 0; $i < $days && count($prosti) < $limit; $i++, $dan = $dan->modify('+1 day')) {
            // ISO: 1 = ponedeljek ... 7 = nedelja, enako kot ai_business_hours.
            $vrstica = $delovnik[(int) $dan->format('N')] ?? null;
            if ($vrstica === null || $vrstica['closed'] || !$vrstica['opens_at'] || !$vrstica['closes_at']) {
                continue;
            }

            [$uraOd, $minOd] = array_map('intval', explode(':', $vrstica['opens_at']));
            [$uraDo, $minDo] = array_map('intval', explode(':', $vrstica['closes_at']));

            $zacetek = $dan->setTime($uraOd, $minOd);
            $konec   = $dan->setTime($uraDo, $minDo);

            while ($zacetek->modify('+' . $durationMin . ' minutes') <= $konec) {
                if ($zacetek >= $najprej && !$this->seKrije($zacetek, $durationMin, $zasedeni)) {
                    $prosti[] = $zacetek->format('Y-m-d H:i');
                    if (count($prosti) >= $limit) {
                        break;
                    }
                }
                $zacetek = $zacetek->modify('+' . $durationMin . ' minutes');
            }
        }

        return $prosti;
    }

    /** @return array[] pari zacetek/konec zasedenih terminov */
    private function zasedeniTermini(DateTimeImmutable $od, DateTimeImmutable $do): array
    {
        try {
            $stmt = $this->pdo()->prepare(
                'SELECT starts_at, duration_min FROM ' . $this->table('appointments') . '
                 WHERE status = :status AND starts_at >= :od AND starts_at < :do'
            );
            $stmt->execute([
                'status' => 'booked',
                'od'     => $od->format('Y-m-d H:i:s'),
                'do'     => $do->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::zasedeniTermini: ' . $e->getMessage());
            throw new AdapterException('Branje terminov ni uspelo.');
        }

        $zasedeni = [];
        foreach ($stmt->fetchAll() as $vrstica) {
            $z = new DateTimeImmutable((string) $vrstica['starts_at']);
            $zasedeni[] = [$z, $z->modify('+' . (int) $vrstica['duration_min'] . ' minutes')];
        }
        return $zasedeni;
    }

    /** @param array[] $zasedeni */
    private function seKrije(DateTimeImmutable $zacetek, int $durationMin, array $zasedeni): bool
    {
        $konec = $zacetek->modify('+' . $durationMin . ' minutes');
        foreach ($zasedeni as [$zasedenOd, $zasedenDo]) {
            if ($zacetek < $zasedenDo && $konec > $zasedenOd) {
                return true;
            }
        }
        return false;
    }

    public function createAppointment(array $appointment): int
    {
        $zacetek = new DateTimeImmutable((string) $appointment['starts_at']);
        $trajanje = max(10, min((int) ($appointment['duration_min'] ?? 30), 240));

        // Med tem, ko je stranka povedala ime in telefon, je termin lahko vzel
        // nekdo drug. Preverimo tik pred zapisom, sicer se dve stranki dobita
        // ob isti uri in to opazi sele podjetje.
        if ($this->seKrije($zacetek, $trajanje, $this->zasedeniTermini(
            $zacetek->modify('-1 day'),
            $zacetek->modify('+1 day')
        ))) {
            return 0;
        }

        try {
            $stmt = $this->pdo()->prepare(
                'INSERT INTO ' . $this->table('appointments') . '
                 (starts_at, duration_min, name, phone, email, note)
                 VALUES (:starts_at, :duration_min, :name, :phone, :email, :note)'
            );
            $stmt->execute([
                'starts_at'    => $zacetek->format('Y-m-d H:i:s'),
                'duration_min' => $trajanje,
                'name'         => (string) ($appointment['name'] ?? ''),
                'phone'        => (string) ($appointment['phone'] ?? ''),
                'email'        => ($appointment['email'] ?? '') === '' ? null : (string) $appointment['email'],
                'note'         => ($appointment['note'] ?? '') === '' ? null : (string) $appointment['note'],
            ]);
            return (int) $this->pdo()->lastInsertId();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::createAppointment: ' . $e->getMessage());
            throw new AdapterException('Rezervacija termina ni uspela.');
        }
    }

    public function listAppointments(array $filter = []): array
    {
        $od = $filter['from'] ?? new DateTimeImmutable('today');

        try {
            $stmt = $this->pdo()->prepare(
                'SELECT id, starts_at, duration_min, name, phone, email, note, status, created_at
                 FROM ' . $this->table('appointments') . '
                 WHERE starts_at >= :od
                 ORDER BY starts_at
                 LIMIT 200'
            );
            $stmt->execute(['od' => $od->format('Y-m-d H:i:s')]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::listAppointments: ' . $e->getMessage());
            throw new AdapterException('Branje terminov ni uspelo.');
        }
    }

    public function cancelAppointment(int $id): bool
    {
        try {
            $stmt = $this->pdo()->prepare(
                'UPDATE ' . $this->table('appointments') . ' SET status = :status WHERE id = :id'
            );
            $stmt->execute(['status' => 'cancelled', 'id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('DirectMySQLAdapter::cancelAppointment: ' . $e->getMessage());
            throw new AdapterException('Odpoved termina ni uspela.');
        }
    }
}
