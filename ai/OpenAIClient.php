<?php
/**
 * Minimalen odjemalec za OpenAI Chat Completions.
 *
 * Namerno pokriva samo tisto, kar potrebujemo: pošlji pogovor z definicijami
 * orodij, vrni sporočilo modela. Brez zunanjih knjižnic (na shared hostingu
 * praviloma ni Composerja).
 */
final class OpenAIClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;
    /** @var int */
    private $timeout;

    public function __construct(string $apiKey, string $model, int $timeout = 20)
    {
        if ($apiKey === '') {
            throw new OpenAIException('Manjka OPENAI_API_KEY v config.php.');
        }
        if (!function_exists('curl_init')) {
            throw new OpenAIException('Na strežniku ni razširitve cURL, ki je potrebna za klic OpenAI.');
        }

        $this->apiKey  = $apiKey;
        $this->model   = $model;
        $this->timeout = $timeout;
    }

    /**
     * @param array $messages Zgodovina pogovora v obliki OpenAI.
     * @param array $tools    Definicije orodij (ai/tool-definitions.json).
     *
     * @return array Sporočilo modela: content in morebitni tool_calls.
     * @throws OpenAIException
     */
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.3, // Nizka: pri cenah in terminih ne želimo ustvarjalnosti.
        ];

        if ($tools) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $body = $this->post($payload);

        if (!isset($body['choices'][0]['message'])) {
            throw new OpenAIException('Odgovor OpenAI nima pričakovane oblike.');
        }

        return $body['choices'][0]['message'];
    }

    /**
     * Prehodne napake (429 = omejitev hitrosti, 5xx = težava pri OpenAI) rešimo
     * s ponovnim poskusom, namesto da bi stranka takoj dobila "sistem ne dela".
     * Nov OpenAI račun ima nizko omejitev zahtevkov na minuto, zato se to zgodi
     * tudi pri normalni rabi.
     */
    private const RETRY_DELAYS_SECONDS = [2, 5];

    private function post(array $payload): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->postOnce($payload);
            } catch (OpenAIException $e) {
                $status = $e->getCode();
                $isTransient = $status === 429 || ($status >= 500 && $status < 600);

                if (!$isTransient || $attempt >= count(self::RETRY_DELAYS_SECONDS)) {
                    throw $e;
                }

                error_log("OpenAIClient: prehodna napaka (HTTP {$status}), ponovni poskus cez "
                    . self::RETRY_DELAYS_SECONDS[$attempt] . ' s');
                sleep(self::RETRY_DELAYS_SECONDS[$attempt]);
                $attempt++;
            }
        }
    }

    private function postOnce(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new OpenAIException('Klic OpenAI ni uspel: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new OpenAIException('OpenAI je vrnil odgovor, ki ni veljaven JSON.');
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new OpenAIException('OpenAI napaka: ' . $message, $status);
        }

        return $decoded;
    }
}

class OpenAIException extends RuntimeException
{
}
