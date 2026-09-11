<?php
/**
 * Preprosta omejitev števila zahtevkov na IP na minuto.
 *
 * Datotečna izvedba, ker shared hosting običajno nima Redisa.
 * Velja samo za klice od zunaj — chat sloj kliče toole neposredno v PHP
 * in gre mimo te omejitve.
 */
final class RateLimiter
{
    /** @var string */
    private $stateDir;
    /** @var int */
    private $limitPerMinute;

    public function __construct(string $stateDir, int $limitPerMinute)
    {
        $this->stateDir       = rtrim($stateDir, '/\\');
        $this->limitPerMinute = $limitPerMinute;
    }

    /**
     * @return bool true = zahtevek je dovoljen
     */
    public function allow(string $identifier): bool
    {
        if ($this->limitPerMinute <= 0) {
            return true;
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0750, true) && !is_dir($this->stateDir)) {
            // Če stanja ne moremo hraniti, raje pustimo promet skozi,
            // kot da bi zaradi pravic na strežniku blokirali vse stranke.
            return true;
        }

        $minute = (int) floor(time() / 60);
        $file   = $this->stateDir . DIRECTORY_SEPARATOR . sha1($identifier) . '.cnt';

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }

        $allowed = true;
        if (flock($handle, LOCK_EX)) {
            $raw   = stream_get_contents($handle) ?: '';
            $parts = explode('|', trim($raw));
            $storedMinute = isset($parts[0]) ? (int) $parts[0] : 0;
            $count        = isset($parts[1]) ? (int) $parts[1] : 0;

            if ($storedMinute !== $minute) {
                $storedMinute = $minute;
                $count        = 0;
            }

            $count++;
            $allowed = $count <= $this->limitPerMinute;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $storedMinute . '|' . $count);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        if (random_int(1, 100) === 1) {
            $this->purgeStale();
        }

        return $allowed;
    }

    private function purgeStale(): void
    {
        $cutoff = time() - 3600;
        foreach ((array) glob($this->stateDir . DIRECTORY_SEPARATOR . '*.cnt') as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
