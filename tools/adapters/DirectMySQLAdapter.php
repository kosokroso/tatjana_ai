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

    /**
     * @param array $config host, name, user, pass, charset
     */
    public function __construct(array $config)
    {
        $this->config = $config;
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

        $sql = 'SELECT id, name, category, unit, price_per_unit, stock_quantity, description
                FROM products
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY (stock_quantity > 0) DESC, price_per_unit ASC
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
                'SELECT id, name, category, unit, price_per_unit, stock_quantity, description
                 FROM products WHERE id = :id AND active = 1'
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
                 FROM orders o
                 JOIN products  p ON p.id = o.product_id
                 JOIN customers c ON c.id = o.customer_id
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
                'SELECT day_of_week, opens_at, closes_at, closed FROM business_hours ORDER BY day_of_week'
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
                'INSERT INTO inquiries (name, phone, email, product, quantity, note, source, status, created_at)
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

    // ----------------------------------------------------------------

    private function mapProduct(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'name'           => $row['name'],
            'category'       => $row['category'],
            'unit'           => $row['unit'],
            'price_per_unit' => (float) $row['price_per_unit'],
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
}
