<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Console\Command;

class McpTokenCommand extends Command
{
    protected $signature = 'mcp:token {email} {--name=cursor} {--player} {--days=}';

    protected $description = 'Выпустить MCP-токен сотруднику или игроку. Секрет печатается один раз.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if ($this->option('player')) {
            $subject = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if (! $subject) {
                $this->error('Игрок с такой почтой не найден.');

                return self::FAILURE;
            }
        } else {
            $subject = Admin::query()->whereRaw('lower(email) = ?', [$email])->first();
            if (! $subject) {
                $this->error('Сотрудник с такой почтой не найден.');

                return self::FAILURE;
            }
            if ($subject->isFired() || $subject->needsEmployment()) {
                $this->error('Уволенный или незакрытая анкета токен не получают.');

                return self::FAILURE;
            }
        }

        $days = $this->option('days');
        [$token, $plain] = McpToken::issue(
            $subject,
            (string) $this->option('name'),
            $days !== null && $days !== '' ? (int) $days : null
        );

        $this->line($plain);
        $this->info('id='.$token->id.' role='.($subject instanceof Admin ? $subject->role : 'player'));
        $this->comment('Сохраните токен сейчас. В базе только его хеш.');

        return self::SUCCESS;
    }
}
