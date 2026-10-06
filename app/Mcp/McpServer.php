<?php

namespace App\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * JSON-RPC MCP 2025-03-26. Stdout — только ответы протокола.
 */
class McpServer
{
    public const PROTOCOL = '2025-03-26';

    public function __construct(private readonly McpCatalog $catalog) {}

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function serveStream($in, $out, McpActor $actor): void
    {
        while (! feof($in)) {
            $line = fgets($in);
            if ($line === false) {
                break;
            }
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            if (stripos($trim, 'Content-Length:') === 0) {
                $length = (int) trim(substr($trim, strlen('Content-Length:')));
                while (($header = fgets($in)) !== false && trim($header) !== '') {
                    // остальные заголовки кадра
                }
                $body = $length > 0 ? (string) fread($in, $length) : '';
                $message = json_decode($body, true);
            } else {
                $message = json_decode($trim, true);
            }

            if (! is_array($message)) {
                $this->write($out, $this->rpcError(null, -32700, 'Parse error'));
                continue;
            }

            $response = $this->dispatch($message, $actor);
            if ($response !== null) {
                $this->write($out, $response);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function dispatch(array $message, McpActor $actor): ?array
    {
        if (array_is_list($message)) {
            return $this->rpcError(null, -32600, 'Batch не поддерживается');
        }
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->rpcError($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $id = $message['id'] ?? null;
        $notification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = $this->method((string) $message['method'], $params, $actor);
        } catch (McpCallException $e) {
            if ($notification) {
                return null;
            }

            return $this->rpcError($id, $e->getCode() !== 0 ? $e->getCode() : -32602, $e->getMessage());
        } catch (\Throwable $e) {
            $this->audit('error', 'mcp.internal', $actor, [
                'method' => $message['method'],
                'error' => $e->getMessage(),
            ]);
            if ($notification) {
                return null;
            }

            return $this->rpcError($id, -32603, 'Внутренняя ошибка');
        }

        if ($notification || $result === null) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function method(string $method, array $params, McpActor $actor): mixed
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'notifications/initialized', 'initialized' => null,
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->catalog->toolsFor($actor)],
            'tools/call' => $this->callTool($actor, $params),
            'resources/list' => ['resources' => $this->catalog->resourcesFor($actor)],
            'resources/templates/list' => ['resourceTemplates' => []],
            'resources/read' => $this->catalog->read($actor, (string) ($params['uri'] ?? '')),
            'prompts/list' => ['prompts' => $this->catalog->promptsFor($actor)],
            'prompts/get' => $this->catalog->prompt(
                $actor,
                (string) ($params['name'] ?? ''),
                is_array($params['arguments'] ?? null) ? $params['arguments'] : []
            ),
            'logging/setLevel' => (object) [],
            default => throw new McpCallException('Method not found', -32601),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? self::PROTOCOL);

        return [
            'protocolVersion' => in_array($requested, ['2024-11-05', self::PROTOCOL, '2025-06-18'], true)
                ? $requested
                : self::PROTOCOL,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => '0451-kosino',
                'version' => '1.0.0',
            ],
            'instructions' => 'Контуры зала и магазина разделены ролью токена. Мутация сначала возвращает превью и ничего не пишет: повторите вызов с теми же аргументами и confirm=true. Облако не шлёт Wake-on-LAN и не запускает PHPUnit. Телефоны, паспорта и КЭДО в контекст не кладутся. Сырого SQL нет.',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(McpActor $actor, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $tool = $this->catalog->findTool($name);
        if ($tool === null) {
            return $this->toolError('Инструмент не найден.');
        }
        if (! $this->catalog->allows($actor, (string) $tool['gate'])) {
            $this->audit('warning', 'mcp.denied', $actor, ['tool' => $name]);

            return $this->toolError('Нет прав на этот инструмент.');
        }

        try {
            $this->catalog->assertCallable($name, $args);
            $confirm = filter_var($args['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($this->catalog->needsConfirm($name, $args)) {
                $key = $this->confirmKey($actor, $name, $args);
                if (! $confirm) {
                    $preview = $this->catalog->preview($actor, $name, $args);
                    Cache::put($key, 1, (int) config('mcp.confirm_ttl', 300));
                    $this->audit('info', 'mcp.preview', $actor, [
                        'tool' => $name,
                        'args' => $this->canonical($args),
                    ]);

                    return $this->toolOk([
                        'needs_confirmation' => true,
                        'confirm_within_seconds' => (int) config('mcp.confirm_ttl', 300),
                        'hint' => 'Повторите вызов с теми же аргументами и confirm=true. До этого данные не меняются.',
                        'preview' => $preview,
                    ]);
                }
                if (! Cache::pull($key)) {
                    return $this->toolError('Нет свежего превью для этих аргументов. Сначала вызовите без confirm.');
                }
            }

            $payload = $this->catalog->call($actor, $name, $args);
            $this->audit(
                $this->catalog->needsConfirm($name, $args) ? 'warning' : 'info',
                'mcp.action',
                $actor,
                ['tool' => $name, 'args' => $this->canonical($args), 'result' => 'ok']
            );

            return $this->toolOk($payload);
        } catch (McpCallException $e) {
            $this->audit('warning', 'mcp.rejected', $actor, ['tool' => $name, 'error' => $e->getMessage()]);

            return $this->toolError($e->getMessage());
        } catch (\Throwable $e) {
            $this->audit('error', 'mcp.action_failed', $actor, [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            $message = $e->getMessage();
            if (str_contains($message, 'SQLSTATE') || str_contains($message, 'vendor/')) {
                $message = 'Внутренняя ошибка. Подробности в storage/logs/mcp-actions.log.';
            }

            return $this->toolError($message);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function confirmKey(McpActor $actor, string $name, array $args): string
    {
        $encoded = json_encode($this->canonical($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'mcp-confirm:'.$actor->token->id.':'.hash('sha256', $name."\n".$encoded);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function canonical(array $args): array
    {
        unset($args['confirm']);

        return $this->sortKeys($args);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortKeys(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->sortKeys($child);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toolOk(array $payload): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(McpMask::scrub($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ]],
            'isError' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => $message,
            ]],
            'isError' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  resource  $out
     */
    private function write($out, array $payload): void
    {
        fwrite($out, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        fflush($out);
    }

    /**
     * @return array<string, mixed>
     */
    private function rpcError(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(string $level, string $event, McpActor $actor, array $context): void
    {
        try {
            $context['actor'] = $actor->auditLabel();
            $context['token'] = $actor->token->id;
            Log::channel('mcp')->{$level}($event, McpMask::scrub($context));
        } catch (\Throwable) {
            // Журнал не должен ломать протокол.
        }
    }
}
