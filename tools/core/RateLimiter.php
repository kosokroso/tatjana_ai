<?php
/**
 * Preprosta omejitev števila zahtevkov v danem časovnem oknu.
 *
 * Datotečna izvedba, ker shared hosting običajno nima Redisa.
 *
 * Okno je nastavljivo, ker minuta in dan lovita različni zlorabi: minuta
 * ustavi naval, dan pa nekoga, ki bi počasi, a ves dan trošil tuj OpenAI kredit.
 */
final class RateLimiter
{
    /** @var string */
    private $stateDir;
    /** @var int */
    private $limit;
    /** @var int */
    private $windowSeconds;

    public function __construct(string $stateDir, int $limit, int $windowSeconds = 60)
    {
        $this->stateDir      = rtrim($stateDir, '/\\');
        $this->limit         = $limit;
        $this->windowSeconds = max(1, $windowSeconds);
    }

    /**
     * @return bool true = zahtevek je dovoljen
     */
    public function allow(string $identifier): bool
    {
        if ($this->limit <= 0) {
            return true;
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0750, true) && !is_dir($this->stateDir)) {
            // Če stanja ne moremo hraniti, raje pustimo promet skozi,
            // kot da bi zaradi pravic na strežniku blokirali vse stranke.
            return true;
        }

        $minute = (int) floor(time() / $this->windowSeconds);
        // Okno je del imena datoteke, sicer bi si minutni in dnevni števec
        // za isti IP povozila stanje.
        $file = $this->stateDir . DIRECTORY_SEPARATOR
            . sha1($identifier) . '-' . $this->windowSeconds . '.cnt';

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
            $allowed = $count <= $this->limit;

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
        // Dnevni števci morajo preživeti dlje od minutnih, sicer bi se
        // omejitev sredi dneva ponastavila.
        $cutoff = time() - max(3600, $this->windowSeconds * 2);
        foreach ((array) glob($this->stateDir . DIRECTORY_SEPARATOR . '*.cnt') as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
