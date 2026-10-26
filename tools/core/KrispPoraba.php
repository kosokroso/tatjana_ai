<?php
/**
 * Mesečna poraba Krisp minut po agentu.
 *
 * Zakaj obstaja: LiveKit na paketu Build vključuje samo 100 minut odstranjevanja
 * glasov v ozadju na mesec in v dokumentaciji ne piše, kaj se zgodi nad mejo
 * (Build nima kartice, da bi se doplačalo). Agent zato ob začetku klica vpraša,
 * koliko sekund je projekt že porabil, in nad mejo preklopi na brezplačni NC.
 *
 * Števec je tu in ne v agentu iz istega razloga kot pri CallLimits: replika v
 * oblaku se kadar koli zažene znova s praznim diskom.
 *
 * Ločen po agentu, ker ima vsak projekt LiveKit svojih 100 minut (Tatjana in
 * Maja sta v dveh projektih, strežnik pa je skupen).
 */
final class KrispPoraba
{
    private string $mapa;

    public function __construct(string $mapa)
    {
        $this->mapa = rtrim($mapa, '/\\');
        if (!is_dir($this->mapa)) {
            @mkdir($this->mapa, 0750, true);
        }
    }

    /** Samo črke, števke, pomišljaj in podčrtaj: ime gre v ime datoteke. */
    public static function jeVeljavenAgent(string $agent): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]{1,32}$/', $agent);
    }

    public function sekundVMesecu(string $agent, ?string $mesec = null): int
    {
        if (!self::jeVeljavenAgent($agent)) {
            return 0;
        }
        $dat = $this->datoteka($agent, $mesec);
        if (!is_file($dat)) {
            return 0;
        }
        return max(0, (int) trim((string) @file_get_contents($dat)));
    }

    public function dodaj(string $agent, int $sekund, ?string $mesec = null): void
    {
        if (!self::jeVeljavenAgent($agent) || $sekund <= 0) {
            return;
        }
        $handle = @fopen($this->datoteka($agent, $mesec), 'c+');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            $trenutno = max(0, (int) trim((string) stream_get_contents($handle)));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($trenutno + $sekund));
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    private function datoteka(string $agent, ?string $mesec): string
    {
        return $this->mapa . '/krisp-' . ($mesec ?? date('Y-m')) . '-' . $agent . '.txt';
    }
}
