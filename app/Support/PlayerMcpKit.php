<?php

namespace App\Support;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Файл подключения игрока к MCP. Токен читает только player:// и ничего не меняет.
 */
final class PlayerMcpKit
{
    public const DAYS = 90;

    /**
     * @return list<array{id: string, title: string}>
     */
    public static function catalog(): array
    {
        return array_map(
            static fn (array $agent): array => ['id' => $agent['id'], 'title' => $agent['title']],
            self::agents()
        );
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_column(self::agents(), 'id');
    }

    /**
     * @return array{agent: string, title: string, filename: string, config: string, where: list<string>, steps: list<string>, expires_at: ?string, ready: bool}
     */
    public function issue(User $user, string $agent): array
    {
        $spec = $this->find($agent);
        McpToken::query()
            ->where('user_id', $user->id)
            ->where('name', $spec['id'])
            ->whereNull('revoked_at')
            ->get()
            ->each(static fn (McpToken $token) => $token->revoke());

        [$token, $plain] = McpToken::issue($user, $spec['id'], self::DAYS);
        try {
            Log::channel('mcp')->info('mcp.player_issue', [
                'actor' => 'user:'.$user->id,
                'token' => $token->id,
                'agent' => $spec['id'],
            ]);
        } catch (\Throwable) {
            //
        }

        $url = rtrim((string) config('app.url'), '/').'/mcp';

        return [
            'agent' => $spec['id'],
            'title' => $spec['title'],
            'filename' => $spec['filename'],
            'config' => $this->config($spec['id'], $url, $plain),
            'where' => $spec['where'],
            'steps' => $spec['steps'],
            'expires_at' => $token->expires_at?->toIso8601String(),
            'ready' => (bool) config('mcp.enabled') && (bool) config('mcp.http_enabled'),
        ];
    }

    /**
     * @return array{id: string, title: string, filename: string, where: list<string>, steps: list<string>}
     */
    private function find(string $agent): array
    {
        foreach (self::agents() as $spec) {
            if ($spec['id'] === $agent) {
                return $spec;
            }
        }

        throw new \InvalidArgumentException('Неизвестный агент.');
    }

    private function config(string $agent, string $url, string $plain): string
    {
        $header = ['Authorization' => 'Bearer '.$plain];
        $payload = match ($agent) {
            'vscode' => [
                'servers' => [
                    '0451-club' => [
                        'type' => 'http',
                        'url' => $url,
                        'headers' => $header,
                    ],
                ],
            ],
            'windsurf' => [
                'mcpServers' => [
                    '0451-club' => [
                        'serverUrl' => $url,
                        'headers' => $header,
                    ],
                ],
            ],
            'claude' => [
                'mcpServers' => [
                    '0451-club' => [
                        'type' => 'http',
                        'url' => $url,
                        'headers' => $header,
                    ],
                ],
            ],
            default => [
                'mcpServers' => [
                    '0451-club' => [
                        'url' => $url,
                        'headers' => $header,
                    ],
                ],
            ],
        };

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{id: string, title: string, filename: string, where: list<string>, steps: list<string>}>
     */
    private static function agents(): array
    {
        $merge = 'Если в файле уже есть другие серверы, добавь только блок 0451-club. Остальное не затирай.';

        return [
            [
                'id' => 'cursor',
                'title' => 'Cursor',
                'filename' => 'mcp.json',
                'where' => [
                    'Windows: %USERPROFILE%\\.cursor\\mcp.json',
                    'macOS и Linux: ~/.cursor/mcp.json',
                ],
                'steps' => [
                    'Скачай файл или скопируй текст и сохрани его по пути выше.',
                    $merge,
                    'В Cursor открой Settings → MCP и включи сервер 0451-club.',
                    'Токен внутри файла. Повторный клик по Cursor гасит предыдущий. Срок 90 дней.',
                ],
            ],
            [
                'id' => 'claude',
                'title' => 'Claude',
                'filename' => 'claude_desktop_config.json',
                'where' => [
                    'Windows: %APPDATA%\\Claude\\claude_desktop_config.json',
                    'macOS: ~/Library/Application Support/Claude/claude_desktop_config.json',
                ],
                'steps' => [
                    'Закрой Claude Desktop.',
                    'Открой файл по пути выше. Если его нет — создай.',
                    $merge,
                    'Запусти Claude снова. Токен внутри файла, срок 90 дней. Повторный клик гасит предыдущий.',
                ],
            ],
            [
                'id' => 'chatgpt',
                'title' => 'ChatGPT',
                'filename' => 'chatgpt-mcp.json',
                'where' => [
                    'ChatGPT → Настройки → Приложения → режим разработчика.',
                    'Файл на диск класть не обязательно. Если просят конфиг — это скачанный JSON, не в git.',
                ],
                'steps' => [
                    'Создай приложение и вставь URL из файла.',
                    'Аутентификация: заголовок Authorization, значение целиком из файла, вместе со словом Bearer.',
                    'Токен показан один раз. Повторный клик по ChatGPT гасит предыдущий. Срок 90 дней.',
                ],
            ],
            [
                'id' => 'vscode',
                'title' => 'VS Code',
                'filename' => 'mcp.json',
                'where' => [
                    'Палитра команд: MCP: Open User Configuration.',
                    'Windows, если открывать руками: %APPDATA%\\Code\\User\\mcp.json',
                ],
                'steps' => [
                    'Вставь содержимое в пользовательский mcp.json, не в репозиторий проекта.',
                    $merge,
                    'В панели MCP включи 0451-club. Токен внутри файла, срок 90 дней.',
                ],
            ],
            [
                'id' => 'windsurf',
                'title' => 'Windsurf',
                'filename' => 'mcp_config.json',
                'where' => [
                    'Windows: %USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
                    'macOS и Linux: ~/.codeium/windsurf/mcp_config.json',
                ],
                'steps' => [
                    'Сохрани файл по пути выше.',
                    $merge,
                    'Перезапусти Windsurf. Токен внутри файла, срок 90 дней. Повторный клик гасит предыдущий.',
                ],
            ],
        ];
    }
}
