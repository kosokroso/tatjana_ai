<?php
/**
 * Dnevna poraba pri OpenAI, merjena v žetonih.
 *
 * Omejitev števila zahtevkov sama po sebi ne varuje denarnice: bot lahko v enem
 * samem klicu pošlje dvajset dolgih sporočil in porabi toliko kot deset kratkih
 * pogovorov. Žetoni so tisto, kar se plača, zato se šteje njih.
 *
 * Datotečna izvedba, ker shared hosting nima Redisa. Števec je dnevni in se ob
 * menjavi datuma sam začne znova.
 */
final class Budget
{
    /** @var string */
    private $file;
    /** @var int */
    private $limit;

    public function __construct(string $stateDir, int $dailyLimit)
    {
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0750, true);
        }
        $this->file  = rtrim($stateDir, '/\\') . DIRECTORY_SEPARATOR . 'poraba-' . date('Y-m-d') . '.txt';
        $this->limit = $dailyLimit;
    }

    /** Ali je dnevna meja že dosežena? Meja 0 ali manj pomeni brez omejitve. */
    public function isExceeded(): bool
    {
        return $this->limit > 0 && $this->used() >= $this->limit;
    }

    public function used(): int
    {
        $raw = @file_get_contents($this->file);
        return $raw === false ? 0 : (int) trim($raw);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Prišteje porabo. Zaklep je nujen, ker lahko dva obiskovalca pišeta hkrati
     * in bi se brez njega ena poraba izgubila.
     */
    public function add(int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            // Če porabe ne moremo beležiti, raje pustimo promet skozi, kot da bi
            // zaradi pravic na strežniku ustavili vse stranke.
            error_log('Budget: datoteke ' . $this->file . ' ni mogoce odpreti');
            return;
        }

        if (flock($handle, LOCK_EX)) {
            $trenutno = (int) trim((string) stream_get_contents($handle));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($trenutno + $tokens));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        if (random_int(1, 50) === 1) {
            $this->purgeOld();
        }
    }

    /** Števci starejši od tedna nimajo več pomena. */
    private function purgeOld(): void
    {
        $cutoff = time() - 7 * 86400;
        foreach ((array) glob(dirname($this->file) . DIRECTORY_SEPARATOR . 'poraba-*.txt') as $f) {
            if (is_file($f) && filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
