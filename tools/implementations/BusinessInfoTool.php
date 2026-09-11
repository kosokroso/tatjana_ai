<?php
/**
 * Podatki o poslovanju: delovni čas, območja dostave, načini plačila.
 *
 * Vhod:  { "info_type": "hours" }
 * Izhod: odvisen od tipa — pri "hours" vključuje tudi izračun, ali je odprto zdaj.
 *
 * Delovni čas bere adapter iz baze, ostalo pride iz data/business-info.json,
 * ki ga lahko podjetje ureja brez posega v kodo.
 */
final class BusinessInfoTool extends Tool
{
    private const TYPES = ['hours', 'delivery_regions', 'payments'];

    /** @var string|null */
    private $infoFile;

    public function __construct(AdapterInterface $adapter, ?string $infoFile = null)
    {
        parent::__construct($adapter);
        $this->infoFile = $infoFile;
    }

    public function name(): string
    {
        return 'business-info';
    }

    public function handle(array $input): ToolResponse
    {
        $type = $this->enumValue($input, 'info_type', self::TYPES, 'hours');

        if ($type === 'hours') {
            return ToolResponse::ok($this->hours());
        }

        $info = $this->loadStaticInfo();
        if (!isset($info[$type])) {
            return ToolResponse::notFound('Tega podatka o poslovanju nimam.');
        }

        return ToolResponse::ok($info[$type]);
    }

    private function hours(): array
    {
        $schedule = $this->adapter->getBusinessHours();
        $now      = new DateTimeImmutable('now');

        $todayDow    = (int) $now->format('N');
        $tomorrowDow = $todayDow === 7 ? 1 : $todayDow + 1;

        $today    = $schedule[$todayDow]    ?? null;
        $tomorrow = $schedule[$tomorrowDow] ?? null;

        $week = [];
        foreach ($schedule as $dow => $row) {
            $week[] = [
                'day'    => SlovenianDate::dayName($dow),
                'closed' => $row['closed'],
                'opens'  => $row['opens_at'],
                'closes' => $row['closes_at'],
            ];
        }

        return [
            'today'         => SlovenianDate::dayName($todayDow),
            'today_open'    => $today !== null && !$today['closed'],
            'opens'         => $today['opens_at']  ?? null,
            'closes'        => $today['closes_at'] ?? null,
            'open_now'      => $this->isOpenNow($today, $now),
            'current_time'  => $now->format('H:i'),
            'tomorrow'      => SlovenianDate::dayName($tomorrowDow),
            'tomorrow_open' => $tomorrow !== null && !$tomorrow['closed'],
            'tomorrow_opens'  => $tomorrow['opens_at']  ?? null,
            'tomorrow_closes' => $tomorrow['closes_at'] ?? null,
            'week'          => $week,
        ];
    }

    private function isOpenNow(?array $today, DateTimeInterface $now): bool
    {
        if ($today === null || $today['closed'] || !$today['opens_at'] || !$today['closes_at']) {
            return false;
        }
        $current = $now->format('H:i');
        return $current >= $today['opens_at'] && $current < $today['closes_at'];
    }

    private function loadStaticInfo(): array
    {
        if ($this->infoFile === null || !is_readable($this->infoFile)) {
            throw new AdapterException('Datoteka s podatki o poslovanju ni dosegljiva.');
        }

        $decoded = json_decode((string) file_get_contents($this->infoFile), true);
        if (!is_array($decoded)) {
            throw new AdapterException('Datoteka s podatki o poslovanju je pokvarjena.');
        }

        return $decoded;
    }
}
