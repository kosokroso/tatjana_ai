<?php
/**
 * Sestavi adapter in toole ter posreduje klice nanje.
 *
 * Vsi klici gredo skozi call(): tam se merijo časi, lovijo napake in piše log —
 * enako za HTTP endpoint in za chat sloj, ki toole kliče neposredno v PHP.
 */
final class ToolRegistry
{
    /** Dovoljeni adapterji. Konfiguracija izbira med njimi po imenu — nikoli
     *  ne instanciramo poljubnega razreda iz nastavitve. */
    private const ADAPTERS = [
        'DirectMySQLAdapter' => DirectMySQLAdapter::class,
        'VascoAdapter'       => VascoAdapter::class,
    ];

    /** @var AdapterInterface */
    private $adapter;
    /** @var Logger */
    private $logger;
    /** @var Tool[] */
    private $tools = [];

    private function __construct(AdapterInterface $adapter, Logger $logger)
    {
        $this->adapter = $adapter;
        $this->logger  = $logger;

        $infoFile = defined('BUSINESS_INFO_FILE')
            ? BUSINESS_INFO_FILE
            : dirname(__DIR__, 2) . '/data/business-info.json';

        $this->register(new ProductTool($adapter));
        $this->register(new OrderTool($adapter, $logger));
        $this->register(new BusinessInfoTool($adapter, $infoFile));
    }

    public static function create(): self
    {
        $adapterName = defined('ADAPTER') ? ADAPTER : 'DirectMySQLAdapter';
        if (!array_key_exists($adapterName, self::ADAPTERS)) {
            throw new AdapterException("Neznan adapter '{$adapterName}' v konfiguraciji.");
        }

        $class   = self::ADAPTERS[$adapterName];
        $adapter = new $class([
            'host'    => DB_HOST,
            'name'    => DB_NAME,
            'user'    => DB_USER,
            'pass'    => DB_PASS,
            'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
        ]);

        $logger = new Logger(
            LOG_DIR,
            defined('LOG_MASK_PII') ? LOG_MASK_PII : true,
            defined('LOG_RETENTION_DAYS') ? LOG_RETENTION_DAYS : 14
        );

        return new self($adapter, $logger);
    }

    private function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * Pokliče tool in vrne odgovor. Ne vrže izjeme — vsaka napaka se prevede
     * v ToolResponse, da AI sloj vedno dobi enako obliko odgovora.
     *
     * @param string $source 'http' ali 'chat' — samo za log
     */
    public function call(string $name, array $input, string $source = 'http', ?string $requestId = null): ToolResponse
    {
        $startedAt = microtime(true);
        $requestId = $requestId ?? bin2hex(random_bytes(6));

        if (!$this->has($name)) {
            $response = ToolResponse::error("Tool '{$name}' ne obstaja.", 'unknown_tool', 404);
        } else {
            try {
                $response = $this->tools[$name]->handle($input);
            } catch (ToolInputException $e) {
                $response = ToolResponse::invalidInput($e->getMessage());
            } catch (AdapterException $e) {
                // Vir podatkov ne dela. AI mora stranko preusmeriti na telefon,
                // ne pa ugibati odgovor.
                error_log("Tool {$name}: " . $e->getMessage());
                $response = ToolResponse::systemError($e->getMessage());
            } catch (Throwable $e) {
                error_log("Tool {$name}: nepričakovana napaka: " . $e->getMessage());
                $response = ToolResponse::systemError('Pri obdelavi zahteve je prišlo do napake.');
            }
        }

        $this->logger->logToolCall([
            'request_id'     => $requestId,
            'source'         => $source,
            'ip'             => $_SERVER['REMOTE_ADDR'] ?? '-',
            'tool'           => $name,
            'args'           => $input,
            'success'        => $response->isSuccess(),
            'error_code'     => $response->errorCode(),
            'result_summary' => $this->summarize($response),
            'duration_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    /** V log ne pišemo celotnega odgovora, samo toliko, da se da slediti. */
    private function summarize(ToolResponse $response): string
    {
        if (!$response->isSuccess()) {
            return 'napaka';
        }
        $data = $response->toArray()['data'];
        if (is_array($data) && isset($data[0])) {
            return count($data) . ' zadetkov';
        }
        if (is_array($data) && isset($data['id'])) {
            return 'zapis #' . $data['id'];
        }
        return 'ok';
    }
}
