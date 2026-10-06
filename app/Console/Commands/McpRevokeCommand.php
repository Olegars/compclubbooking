<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use Illuminate\Console\Command;

class McpRevokeCommand extends Command
{
    protected $signature = 'mcp:revoke {id}';

    protected $description = 'Отозвать MCP-токен по id';

    public function handle(): int
    {
        $token = McpToken::query()->find((int) $this->argument('id'));
        if (! $token) {
            $this->error('Токен не найден.');

            return self::FAILURE;
        }
        $token->revoke();
        $this->info('Отозван #'.$token->id);

        return self::SUCCESS;
    }
}
