<?php
/**
 * Enotna oblika odgovora vseh toolov.
 *
 *   { "success": true,  "data": {...}, "error": null }
 *   { "success": false, "data": null,  "error": "Sporočilo", "error_code": "not_found" }
 */
final class ToolResponse
{
    /** @var bool */
    private $success;
    /** @var mixed */
    private $data;
    /** @var string|null */
    private $error;
    /** @var string|null */
    private $errorCode;
    /** @var int */
    private $httpStatus;

    private function __construct($success, $data, $error, $errorCode, $httpStatus)
    {
        $this->success    = $success;
        $this->data       = $data;
        $this->error      = $error;
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    /**
     * @param mixed $data
     */
    public static function ok($data): self
    {
        return new self(true, $data, null, null, 200);
    }

    /**
     * Koda napake omogoča AI sloju, da loči "ni najdeno" (povej stranki)
     * od "sistem ne dela" (preusmeri na telefon).
     */
    public static function error(string $message, string $errorCode = 'tool_error', int $httpStatus = 400): self
    {
        return new self(false, null, $message, $errorCode, $httpStatus);
    }

    public static function notFound(string $message): self
    {
        return self::error($message, 'not_found', 404);
    }

    public static function invalidInput(string $message): self
    {
        return self::error($message, 'invalid_input', 400);
    }

    public static function systemError(string $message): self
    {
        return self::error($message, 'system_error', 500);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function toArray(): array
    {
        $out = [
            'success' => $this->success,
            'data'    => $this->data,
            'error'   => $this->error,
        ];
        if ($this->errorCode !== null) {
            $out['error_code'] = $this->errorCode;
        }
        return $out;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
