<?php

namespace Tests\Unit;

use App\Services\PlayerNicknameService;
use Tests\TestCase;

class PlayerNicknameServiceTest extends TestCase
{
    public function test_sanitize_drops_separators_and_keeps_letters(): void
    {
        $service = app(PlayerNicknameService::class);

        $this->assertSame('FrostFox', $service->sanitize('Frost_Fox'));
        $this->assertSame('FrostFox', $service->sanitize('Frost-Fox'));
        $this->assertSame('Луна', $service->sanitize('«Луна»'));
        $this->assertSame('Drift', $service->sanitize('ник: drift'));
        $this->assertNull($service->sanitize('Gamer'));
        $this->assertNull($service->sanitize('ab'));
        $this->assertNull($service->sanitize('___---'));
    }
}
