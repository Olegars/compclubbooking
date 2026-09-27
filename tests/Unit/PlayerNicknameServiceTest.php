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

    public function test_guest_choice_keeps_digits_and_separators(): void
    {
        $service = app(PlayerNicknameService::class);

        $this->assertSame('My_Old-Nick', $service->normalizeGuestChoice('  My_Old-Nick  '));
        $this->assertSame('Кибер Кот', $service->normalizeGuestChoice("Кибер   Кот\n"));
        $this->assertSame('Nova7', $service->normalizeGuestChoice('Nova7'));
        $this->assertSame('Player#1234', $service->normalizeGuestChoice('Player#1234'));
        $this->assertSame('-=Neo=-', $service->normalizeGuestChoice('-=Neo=-'));
        $this->assertNull($service->normalizeGuestChoice('A'));
        $this->assertNull($service->normalizeGuestChoice('!!!'));
    }
}
