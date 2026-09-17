<?php
/**
 * Dnevne meje za telefonske klice.
 *
 * Klepet na spletni strani varujeta Budget in RateLimiter, telefon pa je do
 * zdaj tekel brez strehe: kdor koli je lahko klical poljubno dolgo in poljubno
 * pogosto, medtem ko so tekli števci LiveKit, OpenAI, Azure in DIDWW hkrati.
 * En sam ponoven klicatelj bi lahko v eni noči porabil mesečni proračun.
 *
 * Števci morajo živeti tu, na gostovanju, in ne v agentu. Agent teče v oblaku,
 * kjer se replika lahko kadar koli ustavi in zažene znova — njen disk je prazen
 * ob vsakem zagonu, zato bi se števec vrnil na nič ravno takrat, ko bi bil
 * najbolj potreben.
 *
 * Datotečna izvedba iz istega razloga kot pri Budget: shared hosting nima
 * Redisa. Vsak dan ima svojo datoteko in se sam začne znova.
 */
final class CallLimits
{
    /** @var string */
    private $file;
    /** @var int */
    private $maxSecondsPerCall;
    /** @var int */
    private $maxSecondsPerDay;
    /** @var int */
    private $maxCallsPerCaller;

    public function __construct(
        string $stateDir,
        int $maxSecondsPerCall,
        int $maxMinutesPerDay,
        int $maxCallsPerCaller
    ) {
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0750, true);
        }
        $this->file              = rtrim($stateDir, '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'klici-' . date('Y-m-d') . '.json';
        $this->maxSecondsPerCall = $maxSecondsPerCall;
        $this->maxSecondsPerDay  = $maxMinutesPerDay * 60;
        $this->maxCallsPerCaller = $maxCallsPerCaller;
    }

    public function maxSecondsPerCall(): int
    {
        return $this->maxSecondsPerCall;
    }

    /**
     * Telefonske številke ne shranjujemo.
     *
     * Za štetje je dovolj vedeti, da gre za istega klicatelja kot prej — ni
     * treba vedeti, kdo je. Zgoščena vrednost to omogoči, datoteka s seznamom
     * številk vseh klicateljev pa ne nastane. Številke strank, ki oddajo
     * povpraševanje, so v bazi, kamor sodijo, in tam s privolitvijo.
     */
    public static function oznaka(string $caller): string
    {
        $sol = defined('TOOL_SECRET') ? TOOL_SECRET : 'brez-soli';
        return substr(hash_hmac('sha256', $caller, $sol), 0, 32);
    }

    /**
     * Ali sme ta klic naprej? Vrne razlog za zavrnitev ali null, če je vse v redu.
     *
     * Razlog je namenjen dnevniku, ne klicatelju — ta sliši samo vljuden stavek
     * iz agenta. Kdo je pri meji, kako visoka je in koliko je ostalo, ni podatek,
     * ki bi ga bilo pametno povedati nekomu, ki mejo namerno preizkuša.
     */
    public function zavrnitev(string $callerHash): ?string
    {
        $stanje = $this->preberi();

        if ($this->maxSecondsPerDay > 0 && $stanje['skupaj_sekund'] >= $this->maxSecondsPerDay) {
            return 'dnevna_meja_minut';
        }

        $klicatelj = $stanje['klicatelji'][$callerHash] ?? ['klicev' => 0, 'sekund' => 0];
        if ($this->maxCallsPerCaller > 0 && $klicatelj['klicev'] >= $this->maxCallsPerCaller) {
            return 'meja_klicev_na_klicatelja';
        }

        return null;
    }

    /**
     * Zabeleži končan klic.
     *
     * Šteje se ob koncu in ne ob začetku, ker je do konca znano trajanje. Klic,
     * ki se prekine po treh sekundah, ne sme šteti enako kot desetminutni.
     */
    public function zabelezi(string $callerHash, int $sekund): void
    {
        $sekund = max(0, $sekund);

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            // Če beleženja ni mogoče opraviti, klice raje spustimo skozi, kot da
            // bi zaradi pravic na strežniku ostal telefon nem.
            error_log('CallLimits: datoteke ' . $this->file . ' ni mogoce odpreti');
            return;
        }

        if (flock($handle, LOCK_EX)) {
            $stanje = $this->razclene((string) stream_get_contents($handle));

            $stanje['skupaj_sekund'] += $sekund;
            $trenutni = $stanje['klicatelji'][$callerHash] ?? ['klicev' => 0, 'sekund' => 0];
            $stanje['klicatelji'][$callerHash] = [
                'klicev' => $trenutni['klicev'] + 1,
                'sekund' => $trenutni['sekund'] + $sekund,
            ];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($stanje, JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        if (random_int(1, 50) === 1) {
            $this->pobrisiStare();
        }
    }

    /** Za nadzorno ploščo: koliko je danes porabljenega. */
    public function stanje(): array
    {
        $stanje = $this->preberi();
        return [
            'sekund_danes'      => $stanje['skupaj_sekund'],
            'klicev_danes'      => array_sum(array_column($stanje['klicatelji'], 'klicev')),
            'meja_sekund_na_dan' => $this->maxSecondsPerDay,
        ];
    }

    private function preberi(): array
    {
        $raw = @file_get_contents($this->file);
        return $this->razclene($raw === false ? '' : $raw);
    }

    private function razclene(string $raw): array
    {
        $podatki = json_decode(trim($raw), true);
        if (!is_array($podatki)) {
            $podatki = [];
        }

        return [
            'skupaj_sekund' => (int) ($podatki['skupaj_sekund'] ?? 0),
            'klicatelji'    => is_array($podatki['klicatelji'] ?? null) ? $podatki['klicatelji'] : [],
        ];
    }

    /** Števci starejši od tedna nimajo več pomena. */
    private function pobrisiStare(): void
    {
        $meja = time() - 7 * 86400;
        foreach ((array) glob(dirname($this->file) . DIRECTORY_SEPARATOR . 'klici-*.json') as $f) {
            if (is_file($f) && filemtime($f) < $meja) {
                @unlink($f);
            }
        }
    }
}
