<?php
/**
 * Zapisuje vsak klic toola v dnevno datoteko (JSON Lines).
 *
 *   logs/tool-calls-2026-09-11.log
 *
 * Ena vrstica = en klic, primerna za `grep` ali strojno obdelavo.
 * Osebni podatki so maskirani (glej LOG_MASK_PII) — logi na shared hostingu
 * niso varno mesto za telefonske številke in naslove strank.
 */
final class Logger
{
    /** @var string */
    private $logDir;
    /** @var bool */
    private $maskPii;
    /** @var int */
    private $retentionDays;

    /** Ključi, ki vedno vsebujejo osebne podatke. */
    private const PII_PHONE_KEYS = ['phone', 'verify', 'customer_phone', 'caller_id', 'telefon'];
    private const PII_EMAIL_KEYS = ['email', 'customer_email'];
    private const PII_NAME_KEYS  = ['name', 'customer_name', 'ime'];
    private const PII_HIDE_KEYS  = ['address', 'customer_address', 'naslov', 'note'];

    public function __construct(string $logDir, bool $maskPii = true, int $retentionDays = 14)
    {
        $this->logDir        = rtrim($logDir, '/\\');
        $this->maskPii       = $maskPii;
        $this->retentionDays = $retentionDays;
    }

    /**
     * @param array $context tool, source, ip, args, success, error_code, duration_ms
     */
    public function logToolCall(array $context): void
    {
        $entry = [
            'ts'          => date('c'),
            'request_id'  => $context['request_id']  ?? '-',
            'source'      => $context['source']      ?? 'http',
            'ip'          => $this->maskIp($context['ip'] ?? '-'),
            'tool'        => $context['tool']        ?? '-',
            'args'        => $this->scrub($context['args'] ?? []),
            'success'     => (bool) ($context['success'] ?? false),
            'error_code'  => $context['error_code']  ?? null,
            'error'       => $context['error']       ?? null,
            'result'      => $context['result_summary'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
        ];

        $this->write('tool-calls', $entry);
    }

    /**
     * Ločen log za pogovore s stranko: vprašanje -> klicani tooli -> odgovor AI.
     */
    public function logConversation(array $context): void
    {
        $context['ts'] = date('c');
        if (isset($context['user_message'])) {
            $context['user_message'] = $this->scrubText((string) $context['user_message']);
        }
        if (isset($context['assistant_message'])) {
            $context['assistant_message'] = $this->scrubText((string) $context['assistant_message']);
        }
        if (isset($context['tool_calls'])) {
            $context['tool_calls'] = $this->scrub($context['tool_calls']);
        }
        $this->write('conversations', $context);
    }

    private function write(string $prefix, array $entry): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
        $file = $this->logDir . DIRECTORY_SEPARATOR . $prefix . '-' . date('Y-m-d') . '.log';
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Napaka pri pisanju loga ne sme podreti odgovora stranki.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // Čiščenje starih logov občasno, ne ob vsakem klicu.
        if (random_int(1, 50) === 1) {
            $this->purgeOldLogs();
        }
    }

    private function purgeOldLogs(): void
    {
        $cutoff = time() - ($this->retentionDays * 86400);
        foreach ((array) glob($this->logDir . DIRECTORY_SEPARATOR . '*.log') as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    // ----------------------------------------------------------------
    // Maskiranje osebnih podatkov
    // ----------------------------------------------------------------

    /**
     * @param mixed $value
     * @return mixed
     */
    private function scrub($value, ?string $key = null)
    {
        if (!$this->maskPii) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->scrub($v, is_string($k) ? strtolower($k) : null);
            }
            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($key !== null) {
            if (in_array($key, self::PII_HIDE_KEYS, true)) {
                return '[skrito]';
            }
            if (in_array($key, self::PII_PHONE_KEYS, true)) {
                return $this->maskPhone($value);
            }
            if (in_array($key, self::PII_EMAIL_KEYS, true)) {
                return $this->maskEmail($value);
            }
            if (in_array($key, self::PII_NAME_KEYS, true)) {
                return $this->maskName($value);
            }
        }

        return $this->scrubText($value);
    }

    /** Prosto besedilo lahko vsebuje številko ali e-pošto sredi stavka. */
    private function scrubText(string $text): string
    {
        if (!$this->maskPii) {
            return $text;
        }
        $text = (string) preg_replace_callback(
            '/[\w.+-]+@[\w-]+\.[\w.-]+/u',
            function ($m) { return $this->maskEmail($m[0]); },
            $text
        );
        return (string) preg_replace_callback(
            '/(?:\+?\d[\d\s\/-]{7,}\d)/u',
            function ($m) { return $this->maskPhone($m[0]); },
            $text
        );
    }

    private function maskPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 4) {
            return '[tel]';
        }
        return '[tel:***' . substr($digits, -3) . ']';
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '[email]';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private function maskName(string $name): string
    {
        $initials = [];
        foreach (preg_split('/\s+/u', trim($name)) as $part) {
            if ($part !== '') {
                $initials[] = mb_substr($part, 0, 1) . '.';
            }
        }
        return $initials ? implode(' ', $initials) : '[ime]';
    }

    /** Zadnji oktet IP naslova je prav tako osebni podatek. */
    private function maskIp(string $ip): string
    {
        if (!$this->maskPii || $ip === '-') {
            return $ip;
        }
        if (strpos($ip, '.') !== false) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = 'x';
                return implode('.', $parts);
            }
        }
        if (strpos($ip, ':') !== false) {
            $parts = array_slice(explode(':', $ip), 0, 3);
            return implode(':', $parts) . '::x';
        }
        return $ip;
    }
}
