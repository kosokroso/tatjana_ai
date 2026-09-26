<?php
/**
 * Naročanje na termin.
 *
 * Vhod:  { "action": "find" }                       -> prosti termini
 *        { "action": "book", "starts_at": "...", "name": "...", "phone": "..." }
 *
 * Prosti termini se izpeljejo iz delovnega časa in že zasedenih vrstic, ne iz
 * vnaprej pripravljene tabele. Tabelo bi moral nekdo vzdrževati, in prvi teden,
 * ko tega ne bi storil, bi asistent ponudil termin, ki ga ni.
 */
final class AppointmentTool extends Tool
{
    private const ACTIONS = ['find', 'book'];

    public function name(): string
    {
        return 'appointment';
    }

    public function handle(array $input): ToolResponse
    {
        $action = $this->enumValue($input, 'action', self::ACTIONS, 'find');

        return $action === 'book' ? $this->book($input) : $this->find();
    }

    private function trajanje(): int
    {
        return defined('APPOINTMENT_MINUTES') ? (int) APPOINTMENT_MINUTES : 30;
    }

    private function find(): ToolResponse
    {
        $dni = defined('APPOINTMENT_DAYS_AHEAD') ? (int) APPOINTMENT_DAYS_AHEAD : 14;
        $zdaj = new DateTimeImmutable('now');

        $prosti = $this->adapter->findFreeSlots($zdaj, $dni, $this->trajanje(), 6);

        if (!$prosti) {
            return ToolResponse::ok([
                'slots' => [],
                'note'  => 'V naslednjih ' . $dni . ' dneh ni prostega termina. '
                    . 'Ne izmišljaj si ga; ponudi, da zabeležiš povpraševanje in da se javimo.',
            ]);
        }

        return ToolResponse::ok([
            'duration_min' => $this->trajanje(),
            'slots'        => array_map(function (string $kdaj) use ($zdaj): array {
                $t = new DateTimeImmutable($kdaj);
                return [
                    'starts_at' => $kdaj,
                    // Model naj pove datum tako, kot ga povedo ljudje: "jutri ob
                    // desetih", ne "2026-09-27 10:00".
                    'spoken'    => $this->povedano($t, $zdaj),
                ];
            }, $prosti),
        ]);
    }

    private function povedano(DateTimeImmutable $termin, DateTimeImmutable $zdaj): string
    {
        $relativno = SlovenianDate::relativeDay($termin, $zdaj);
        $dan = $relativno ?? (SlovenianDate::dayName((int) $termin->format('N'))
            . ', ' . SlovenianDate::longDate($termin));

        return $dan . ' ob ' . ltrim($termin->format('H:i'), '0');
    }

    private function book(array $input): ToolResponse
    {
        $kdaj  = $this->requireString($input, 'starts_at', 20);
        $ime   = $this->requireString($input, 'name', 160);
        $tel   = $this->requireString($input, 'phone', 40);

        $termin = DateTimeImmutable::createFromFormat('Y-m-d H:i', $kdaj)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $kdaj);

        if ($termin === false) {
            return ToolResponse::invalidInput('Termin mora biti v obliki 2026-09-27 10:00.');
        }
        if ($termin < new DateTimeImmutable('now')) {
            return ToolResponse::invalidInput('Ta termin je že mimo.');
        }

        $stevke = (string) preg_replace('/\D+/', '', $tel);
        if (strlen($stevke) < 8) {
            return ToolResponse::invalidInput('Telefonska številka ni videti popolna.');
        }

        $eposta = trim((string) ($input['email'] ?? ''));
        if ($eposta !== '' && !filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
            return ToolResponse::invalidInput('E-poštni naslov ni veljaven.');
        }

        $id = $this->adapter->createAppointment([
            'starts_at'    => $termin->format('Y-m-d H:i:s'),
            'duration_min' => $this->trajanje(),
            'name'         => $ime,
            'phone'        => $tel,
            'email'        => $eposta,
            'note'         => trim((string) ($input['note'] ?? '')),
        ]);

        if ($id === 0) {
            // Nekdo je termin vzel med pogovorom. To ni napaka sistema in model
            // se ne sme opravicevati kot za okvaro - ponuditi mora drugega.
            return ToolResponse::ok([
                'booked' => false,
                'note'   => 'Ta termin je bil pravkar zaseden. Povej to in ponudi drugega s seznama.',
            ]);
        }

        return ToolResponse::ok([
            'booked'       => true,
            'id'           => $id,
            'starts_at'    => $termin->format('Y-m-d H:i'),
            'spoken'       => $this->povedano($termin, new DateTimeImmutable('now')),
            'duration_min' => $this->trajanje(),
        ]);
    }
}
