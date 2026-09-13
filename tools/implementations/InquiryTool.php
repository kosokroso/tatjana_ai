<?php
/**
 * Oddaja povpraševanja stranke.
 *
 * Vhod:  { "name": "Janez Novak", "phone": "041 234 567", "email": "janez@example.com",
 *          "product": "napredna spletna stran", "quantity": "1", "note": "..." }
 * Izhod: številka povpraševanja, ki jo asistent pove stranki.
 *
 * Ime, telefon IN e-pošta so obvezni: brez e-pošte podjetje ne more poslati
 * ponudbe, brez telefona pa ne more poklicati nazaj.
 *
 * Povpraševanje NI naročilo. Podjetje stranko pokliče nazaj, potrdi ceno in
 * termin. Asistent tega ne sme predstaviti kot potrjen posel.
 *
 * Zapis v bazo je vir resnice; e-pošta je samo obvestilo. Če pošiljanje ne uspe,
 * povpraševanje vseeno ostane shranjeno in odgovor stranki je enak — sicer bi
 * zaradi težave s poštnim strežnikom izgubili posel.
 */
final class InquiryTool extends Tool
{
    /** Krajša številka skoraj zagotovo pomeni, da je model kaj narobe slišal. */
    private const MIN_PHONE_DIGITS = 8;

    public function name(): string
    {
        return 'submit-inquiry';
    }

    public function handle(array $input): ToolResponse
    {
        $name  = $this->requireString($input, 'name', 120);
        $phone = $this->requireString($input, 'phone', 40);
        $email = $this->requireString($input, 'email', 160);

        $product  = $this->optionalString($input, 'product', 160);
        $quantity = $this->optionalString($input, 'quantity', 60);
        $note     = $this->optionalString($input, 'note', 500);

        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < self::MIN_PHONE_DIGITS) {
            return ToolResponse::invalidInput(
                'Telefonska številka ni videti popolna. Prosi stranko, naj jo pove še enkrat.'
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ToolResponse::invalidInput(
                'E-poštni naslov ni veljaven. Prosi stranko, naj ga pove še enkrat, po črkah.'
            );
        }

        $inquiry = [
            'name'     => $name,
            'phone'    => $phone,
            'email'    => $email,
            'product'  => $product,
            'quantity' => $quantity,
            'note'     => $note,
            'source'   => 'chat',
        ];

        $id = $this->adapter->createInquiry($inquiry);

        $this->notifyBusiness($id, $inquiry);

        return ToolResponse::ok([
            'id'      => $id,
            'message' => 'Povpraševanje je zabeleženo pod številko ' . $id
                . '. Podjetje se javi stranki na navedeno telefonsko številko.',
        ]);
    }

    /**
     * Obvestilo podjetju. Vse vrednosti gredo v telo sporočila, nikoli v glave —
     * vrednost z znakom za novo vrstico bi sicer omogočila vrivanje glav
     * (header injection) in zlorabo strežnika za pošiljanje neželene pošte.
     */
    private function notifyBusiness(int $id, array $inquiry): void
    {
        $to   = defined('INQUIRY_EMAIL_TO')   ? trim(INQUIRY_EMAIL_TO)   : '';
        $from = defined('INQUIRY_EMAIL_FROM') ? trim(INQUIRY_EMAIL_FROM) : '';

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $lines = [
            'Novo povpraševanje prek spletnega asistenta.',
            '',
            'Številka:  ' . $id,
            'Ime:       ' . $inquiry['name'],
            'Telefon:   ' . $inquiry['phone'],
            'E-pošta:   ' . ($inquiry['email'] ?? '-'),
            'Izdelek:   ' . ($inquiry['product'] ?? '-'),
            'Količina:  ' . ($inquiry['quantity'] ?? '-'),
            'Opomba:    ' . ($inquiry['note'] ?? '-'),
            '',
            'Prejeto:   ' . date('d.m.Y H:i'),
        ];

        $body = implode("\n", array_map('trim', $lines));

        $subject = '=?UTF-8?B?' . base64_encode('Novo povpraševanje #' . $id) . '?=';

        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $headers .= 'From: ' . $from . "\r\n";
        }

        if (!@mail($to, $subject, $body, $headers)) {
            // Povpraševanje je v bazi, zato to ni napaka za stranko — samo zapis
            // za podjetje, da obvestila ni dobilo po pošti.
            error_log("InquiryTool: obvestila za povprasevanje #{$id} ni bilo mogoce poslati na {$to}");
        }
    }
}
