<?php
/**
 * Majhen odjemalec SMTP.
 *
 * Obstaja zato, ker je na tem gostovanju mail() izklopljen, naložiti WordPress
 * in uporabiti wp_mail() pa ni mogoče: naš config.php in wp-config.php
 * definirata iste konstante (DB_NAME, DB_USER, DB_HOST), zato bi WordPress v
 * istem procesu dobil naše vrednosti namesto svojih.
 *
 * Pošilja navadno besedilo v UTF-8. Nič drugega ne potrebujemo, zato tudi ni
 * priponk, HTML različice in več prejemnikov — vsaka od teh stvari prinese
 * svoje robne primere.
 */
final class Mailer
{
    /** @var array host, port, secure ('ssl'|'tls'|''), user, pass, timeout */
    private $config;
    /** @var resource|null */
    private $socket = null;

    public function __construct(array $config)
    {
        $this->config = $config + [
            'port'    => 465,
            'secure'  => 'ssl',
            'timeout' => 10,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['host']) && !empty($this->config['user']);
    }

    /**
     * @throws MailerException ob katerikoli napaki v pogovoru s strežnikom
     */
    public function send(string $to, string $subject, string $body, string $fromEmail, string $fromName = ''): void
    {
        if (!$this->isConfigured()) {
            throw new MailerException('SMTP ni nastavljen.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new MailerException('Neveljaven e-poštni naslov.');
        }
        if (!function_exists('stream_socket_client')) {
            throw new MailerException('Odpiranje vtičnic na tem strežniku ni dovoljeno.');
        }

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $fromEmail . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250);
            $this->command('DATA', 354);

            $this->write($this->buildMessage($to, $subject, $body, $fromEmail, $fromName) . "\r\n.");
            $this->expect(250);

            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    // ----------------------------------------------------------------

    private function connect(): void
    {
        $host = $this->config['host'];
        // Pri vratih 465 je povezava šifrirana od prve bajte; pri 587 se
        // šifriranje vklopi kasneje z ukazom STARTTLS.
        if ($this->config['secure'] === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $koda    = 0;
        $sporocilo = '';
        $socket  = @stream_socket_client(
            $host . ':' . (int) $this->config['port'],
            $koda,
            $sporocilo,
            (float) $this->config['timeout']
        );

        if ($socket === false) {
            throw new MailerException('Povezava s poštnim strežnikom ni uspela: ' . $sporocilo);
        }

        stream_set_timeout($socket, (int) $this->config['timeout']);
        $this->socket = $socket;

        $this->expect(220);
    }

    private function handshake(): void
    {
        $ime = $this->clientHostname();
        $this->command('EHLO ' . $ime, 250);

        if ($this->config['secure'] === 'tls') {
            $this->command('STARTTLS', 220);
            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($ok !== true) {
                throw new MailerException('Vzpostavitev šifriranja TLS ni uspela.');
            }
            // Po STARTTLS se pogovor začne znova.
            $this->command('EHLO ' . $ime, 250);
        }
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode((string) $this->config['user']), 334);
        $this->command(base64_encode((string) $this->config['pass']), 235);
    }

    private function buildMessage(string $to, string $subject, string $body, string $fromEmail, string $fromName): string
    {
        $from = $fromName !== ''
            ? $this->encodeHeader($fromName) . ' <' . $fromEmail . '>'
            : $fromEmail;

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->clientHostname() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        // Base64 se izogne tako predolgim vrsticam kot znakom, ki jih SMTP ne prenese.
        return implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");
    }

    /** Naslovi z ne-ASCII znaki morajo biti kodirani, sicer jih strežnik zavrne. */
    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function clientHostname(): string
    {
        $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        return preg_match('/^[A-Za-z0-9.\-]+$/', (string) $host) ? (string) $host : 'localhost';
    }

    private function command(string $line, int $expected): void
    {
        $this->write($line);
        $this->expect($expected);
    }

    private function write(string $line): void
    {
        if (@fwrite($this->socket, $line . "\r\n") === false) {
            throw new MailerException('Pisanje na poštni strežnik ni uspelo.');
        }
    }

    /**
     * Prebere odgovor in preveri kodo. Večvrstični odgovor ima na četrtem
     * mestu vezaj ("250-"), zadnja vrstica presledek ("250 ").
     */
    private function expect(int $expected): void
    {
        $odgovor = '';

        while (true) {
            $vrstica = @fgets($this->socket, 1024);
            if ($vrstica === false) {
                $stanje = stream_get_meta_data($this->socket);
                throw new MailerException(
                    !empty($stanje['timed_out'])
                        ? 'Poštni strežnik se ni odzval v predvidenem času.'
                        : 'Branje odgovora poštnega strežnika ni uspelo.'
                );
            }

            $odgovor .= $vrstica;
            if (strlen($vrstica) < 4 || $vrstica[3] !== '-') {
                break;
            }
        }

        $koda = (int) substr($odgovor, 0, 3);
        if ($koda !== $expected) {
            throw new MailerException('Poštni strežnik je vrnil: ' . trim($odgovor));
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}

class MailerException extends RuntimeException
{
}
