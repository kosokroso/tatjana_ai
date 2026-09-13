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

        // Povpraševanje je shranjeno; od tu naprej ne sme nič več pasti.
        // Brez tega ovoja bi izklopljen mail() na strežniku podrl cel klic in
        // stranka bi dobila napako, čeprav je njen zapis varno v bazi.
        try {
            $this->notifyBusiness($id, $inquiry);
        } catch (Throwable $e) {
            error_log("InquiryTool: obvestila za povprasevanje #{$id} ni bilo mogoce poslati: " . $e->getMessage());
        }

        return ToolResponse::ok([
            'id'      => $id,
            'message' => 'Povpraševanje je zabeleženo pod številko ' . $id
                . '. Podjetje pripravi ponudbo in jo pošlje na navedeni e-poštni naslov.',
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

        $body    = implode("\n", array_map('trim', $lines));
        $subject = 'Novo povpraševanje #' . $id;

        $mailer = new Mailer([
            'host'    => defined('SMTP_HOST')   ? SMTP_HOST   : '',
            'port'    => defined('SMTP_PORT')   ? SMTP_PORT   : 465,
            'secure'  => defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl',
            'user'    => defined('SMTP_USER')   ? SMTP_USER   : '',
            'pass'    => defined('SMTP_PASS')   ? SMTP_PASS   : '',
        ]);

        if ($mailer->isConfigured()) {
            // Pošiljatelj mora biti naslov na lastni domeni, sicer ga prejemnikovi
            // strežniki zavrnejo zaradi SPF. Naslov stranke gre v telo, ne sem.
            $mailer->send(
                $to,
                $subject,
                $body,
                $from !== '' ? $from : (string) SMTP_USER,
                defined('BUSINESS_NAME') ? BUSINESS_NAME : ''
            );
            return;
        }

        if (!function_exists('mail')) {
            error_log('InquiryTool: SMTP ni nastavljen, mail() pa na tem strezniku ni na voljo');
            return;
        }

        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $headers .= 'From: ' . $from . "\r\n";
        }

        if (!@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers)) {
            error_log("InquiryTool: obvestila za povprasevanje #{$id} ni bilo mogoce poslati na {$to}");
        }
    }
}
