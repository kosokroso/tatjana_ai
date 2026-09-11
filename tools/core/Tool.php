<?php
/**
 * Osnovni razred za vse toole.
 *
 * Tool prejme vhodne podatke kot polje in vrne ToolResponse. Ne ve, ali je
 * bil poklican prek HTTP ali neposredno iz chat sloja, in ne ve, od kod
 * adapter jemlje podatke.
 */
abstract class Tool
{
    /** @var AdapterInterface */
    protected $adapter;

    public function __construct(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    /** Ime toola, kot ga uporablja HTTP pot in AI definicija. */
    abstract public function name(): string;

    /**
     * @param array $input Dekodiran JSON zahtevka.
     * @throws ToolInputException ob neveljavnem vhodu
     */
    abstract public function handle(array $input): ToolResponse;

    // ----------------------------------------------------------------
    // Pomočniki za validacijo vhoda
    // ----------------------------------------------------------------

    /**
     * @throws ToolInputException
     */
    protected function requireString(array $input, string $key, int $maxLength = 200): string
    {
        $value = $this->optionalString($input, $key, $maxLength);
        if ($value === null || $value === '') {
            throw new ToolInputException("Manjka obvezen parameter '{$key}'.");
        }
        return $value;
    }

    /**
     * @throws ToolInputException
     */
    protected function optionalString(array $input, string $key, int $maxLength = 200): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (is_int($input[$key]) || is_float($input[$key])) {
            $input[$key] = (string) $input[$key];
        }
        if (!is_string($input[$key])) {
            throw new ToolInputException("Parameter '{$key}' mora biti besedilo.");
        }
        $value = trim($input[$key]);
        if (mb_strlen($value) > $maxLength) {
            throw new ToolInputException("Parameter '{$key}' je predolg (največ {$maxLength} znakov).");
        }
        return $value;
    }

    /**
     * Vrednost mora biti ena od naštetih — prepreči, da bi model izmislil akcijo.
     *
     * @throws ToolInputException
     */
    protected function enumValue(array $input, string $key, array $allowed, ?string $default = null): ?string
    {
        $value = $this->optionalString($input, $key, 40);
        if ($value === null || $value === '') {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw new ToolInputException(
                "Neveljavna vrednost za '{$key}'. Dovoljeno: " . implode(', ', $allowed) . '.'
            );
        }
        return $value;
    }

    /**
     * @throws ToolInputException
     */
    protected function requireId(array $input, string $key): int
    {
        $raw = $this->requireString($input, $key, 20);
        // Dovolimo "10005", " 10005 " in "#10005" — stranke številke narekujejo različno.
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if ($digits === '' || !ctype_digit($digits)) {
            throw new ToolInputException("Parameter '{$key}' mora biti številka.");
        }
        return (int) $digits;
    }
}

/**
 * Vhod ni veljaven. Endpoint to prevede v 400 s čistim sporočilom,
 * namesto da bi PHP vrnil 500.
 */
class ToolInputException extends InvalidArgumentException
{
}
