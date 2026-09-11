<?php
/**
 * Stanje naročila in termin dostave.
 *
 * Vhod:  { "order_id": "10005", "verify": "041 234 567" }
 * Izhod: status, termin dostave, izdelek, količina.
 *
 * VARNOST — zakaj je "verify" obvezen:
 * Naročilne številke so zaporedne in jih je trivialno uganiti. Brez preverjanja
 * identitete bi kdorkoli, ki pokliče in reče "številka 10005", izvedel ime,
 * naslov in termin dostave tuje stranke. Zato mora klicatelj povedati tudi
 * telefon ali e-pošto, s katerim je bilo naročilo oddano.
 *
 * Ob neujemanju vrnemo ISTI odgovor kot pri neobstoječem naročilu — sicer bi
 * napadalec iz razlike med sporočiloma ugotovil, katere številke obstajajo.
 */
final class OrderTool extends Tool
{
    /** Zadnjih toliko številk primerjamo pri telefonu. */
    private const PHONE_MATCH_DIGITS = 8;

    /** @var Logger|null */
    private $logger;

    public function __construct(AdapterInterface $adapter, ?Logger $logger = null)
    {
        parent::__construct($adapter);
        $this->logger = $logger;
    }

    public function name(): string
    {
        return 'order-lookup';
    }

    public function handle(array $input): ToolResponse
    {
        $orderId = $this->requireId($input, 'order_id');
        $verify  = $this->requireString($input, 'verify', 120);

        $order = $this->adapter->findOrderById($orderId);

        if ($order === null || !$this->identityMatches($order, $verify)) {
            if ($order !== null && $this->logger !== null) {
                // Zavrnjen dostop do obstoječega naročila je varnostni dogodek.
                $this->logger->logToolCall([
                    'tool'       => $this->name(),
                    'source'     => 'verification',
                    'args'       => ['order_id' => $orderId, 'verify' => $verify],
                    'success'    => false,
                    'error_code' => 'verification_failed',
                    'error'      => 'Podatek za preverjanje se ne ujema z lastnikom naročila.',
                ]);
            }

            return ToolResponse::notFound(
                'Naročila s to številko in tem kontaktnim podatkom ne najdem. '
                . 'Preveri številko naročila in telefonsko številko, s katero je bilo oddano.'
            );
        }

        return ToolResponse::ok($this->formatOrder($order));
    }

    /**
     * Ujemanje po telefonu ali e-pošti.
     *
     * Telefon primerjamo po zadnjih osmih števkah, ker isto številko ljudje
     * navedejo različno: "+386 41 234 567", "041 234 567", "041234567".
     */
    private function identityMatches(array $order, string $verify): bool
    {
        $verify = trim($verify);

        if (strpos($verify, '@') !== false) {
            $given = mb_strtolower($verify);
            $known = mb_strtolower((string) $order['customer_email']);
            return $known !== '' && hash_equals($known, $given);
        }

        $givenDigits = (string) preg_replace('/\D+/', '', $verify);
        $knownDigits = (string) preg_replace('/\D+/', '', (string) $order['customer_phone']);

        if (strlen($givenDigits) < self::PHONE_MATCH_DIGITS || strlen($knownDigits) < self::PHONE_MATCH_DIGITS) {
            return false;
        }

        return hash_equals(
            substr($knownDigits, -self::PHONE_MATCH_DIGITS),
            substr($givenDigits, -self::PHONE_MATCH_DIGITS)
        );
    }

    /**
     * Vrnemo samo tisto, kar stranka o svojem naročilu sme slišati.
     * Telefon, e-pošta in naslov ostanejo v bazi — AI jih ne potrebuje.
     */
    private function formatOrder(array $order): array
    {
        $today    = new DateTimeImmutable('today');
        $delivery = $order['delivery_date'] ? new DateTimeImmutable($order['delivery_date']) : null;

        $data = [
            'id'              => $order['id'],
            'status'          => $order['status'],
            'status_display'  => $this->statusPhrase($order['status']),
            'order_date'      => $order['order_date'],
            'delivery_date'   => $order['delivery_date'],
            'customer_name'   => $order['customer_name'],
            'items'           => [[
                'name'     => $order['product_name'],
                'quantity' => $order['quantity'],
                'unit'     => $order['product_unit'],
            ]],
        ];

        if ($delivery !== null) {
            $relative = SlovenianDate::relativeDay($delivery, $today);
            $data['delivery_display'] = SlovenianDate::longDate($delivery)
                . ($relative !== null ? ' (' . $relative . ')' : '');
            $data['delivery_is_past'] = $delivery < $today;
        } else {
            $data['delivery_display'] = null;
            $data['delivery_is_past'] = false;
        }

        return $data;
    }

    private function statusPhrase(string $status): string
    {
        $phrases = [
            'pending'   => 'prejeto, termin dostave še ni potrjen',
            'scheduled' => 'potrjeno, dostava je dogovorjena',
            'delivered' => 'dostavljeno',
            'cancelled' => 'preklicano',
        ];
        return $phrases[$status] ?? $status;
    }
}
