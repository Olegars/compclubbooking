<?php

namespace App\Console\Commands;

use App\Mcp\McpActor;
use App\Mcp\McpServer;
use App\Models\McpToken;
use Illuminate\Console\Command;

class McpServeCommand extends Command
{
    protected $signature = 'mcp:serve';

    protected $description = 'MCP 0451 по stdin/stdout. Токен — переменная MCP_TOKEN. В stdout только JSON-RPC.';

    public function handle(McpServer $server): int
    {
        if (! config('mcp.enabled')) {
            fwrite(STDERR, "MCP выключен (MCP_ENABLED).\n");

            return self::FAILURE;
        }

        $token = McpToken::authenticate((string) getenv('MCP_TOKEN'));
        $actor = $token ? McpActor::fromToken($token) : null;
        if (! $actor) {
            fwrite(STDERR, "MCP_TOKEN не принят.\n");

            return self::FAILURE;
        }

        $server->serveStream(STDIN, STDOUT, $actor);

        return self::SUCCESS;
    }
}
