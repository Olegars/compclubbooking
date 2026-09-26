<?php

namespace Tests\Unit;

use App\Support\RussianIdentity;
use RuntimeException;
use Tests\TestCase;

class RussianIdentityTest extends TestCase
{
    public function test_snils_and_inn_checksums(): void
    {
        $this->assertSame('112-233-445 95', RussianIdentity::normalizeSnils('11223344595'));
        $this->assertSame('500100732259', RussianIdentity::normalizeInn('500100732259'));
        $this->assertTrue(RussianIdentity::employerInnOk('770000000082'));

        $this->expectException(RuntimeException::class);
        RussianIdentity::normalizeSnils('112-233-445 00');
    }
}
