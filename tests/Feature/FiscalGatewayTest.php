<?php

namespace Tests\Feature;

use App\Jobs\ProcessFiscalReceipt;
use App\Models\FiscalJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FiscalGatewayService;
use App\Services\FiscalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiscalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'fiscal-relay-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fiscal.enabled' => true,
            'fiscal.relay_token' => $this->token,
            'fiscal.kkm.tax' => -1,
            'fiscal.kkm.inn_kassa' => '7700000000',
            'fiscal.kkm.cashier_name' => 'Касса',
            'fiscal.stale_claim_minutes' => 5,
        ]);

        Http::preventStrayRequests();
    }

    public function test_payment_enqueues_one_electronic_job_and_gateway_closes_it(): void
    {
        $tx = $this->makeDeposit();

        $this->assertSame('pending', $tx->fiscal_status);
        $this->assertNull($tx->fiscal_receipt_url);
        $this->assertSame(1, FiscalJob::query()->count());

        $job = FiscalJob::query()->first();
        $this->assertSame(FiscalJob::KIND_FISCALIZE, $job->kind);
        $this->assertTrue($job->payload['electronically']);
        $this->assertSame(3, $job->payload['items'][0]['payment_method']);
        $this->assertEquals(100.0, (float) $job->payload['payments']['electronic']);
        $this->assertArrayNotHasKey('Password', $job->payload);
        $this->assertArrayNotHasKey('Command', $job->payload);

        $externalId = (string) $job->external_id;

        (new ProcessFiscalReceipt($tx->id))->handle(app(FiscalService::class));
        $this->assertSame(1, FiscalJob::query()->count());
        $this->assertSame($externalId, (string) FiscalJob::query()->value('external_id'));

        $this->getJson('/api/fiscal/targets?token=wrong')->assertUnauthorized();

        $claimed = $this->getJson('/api/fiscal/targets?token='.$this->token)
            ->assertOk()
            ->json();

        $this->assertTrue($claimed['enabled']);
        $this->assertCount(1, $claimed['jobs']);
        $this->assertSame($externalId, $claimed['jobs'][0]['external_id']);
        $this->assertSame(FiscalJob::STATUS_CLAIMED, $job->fresh()->status);

        $this->postJson('/api/fiscal/applied', [
            'token' => $this->token,
            'results' => [[
                'id' => $job->id,
                'outcome' => 'success',
                'fn' => '9999078900001234',
                'fd' => '42',
                'fp' => '1234567890',
                'receipt_url' => 'https://ofd.example.test/r/42',
            ]],
        ])->assertOk();

        $tx->refresh();
        $this->assertSame('success', $tx->fiscal_status);
        $this->assertSame('https://ofd.example.test/r/42', $tx->fiscal_receipt_url);
        $this->assertSame('42', (string) $tx->receipt_id);

        $again = $this->getJson('/api/fiscal/targets?token='.$this->token)->json();
        $this->assertSame(0, $again['count']);
    }

    public function test_uncertain_and_retry_keep_the_same_external_id(): void
    {
        $tx = $this->makeDeposit();
        $job = FiscalJob::query()->first();
        $externalId = (string) $job->external_id;

        $this->getJson('/api/fiscal/targets?token='.$this->token)->assertOk();

        $this->postJson('/api/fiscal/applied', [
            'token' => $this->token,
            'results' => [[
                'id' => $job->id,
                'outcome' => 'uncertain',
                'error' => 'таймаут после команды',
            ]],
        ])->assertOk();

        $tx->refresh();
        $this->assertSame('uncertain', $tx->fiscal_status);
        $this->assertSame(FiscalJob::STATUS_UNCERTAIN, $job->fresh()->status);

        app(FiscalGatewayService::class)->requeueFiscalize($tx->fresh());

        $job->refresh();
        $this->assertSame(FiscalJob::STATUS_PENDING, $job->status);
        $this->assertSame($externalId, (string) $job->external_id);
        $this->assertSame('pending', $tx->fresh()->fiscal_status);
        $this->assertSame(1, FiscalJob::query()->where('kind', FiscalJob::KIND_FISCALIZE)->count());
    }

    public function test_success_without_fiscal_proof_stays_an_error(): void
    {
        $tx = $this->makeDeposit();
        $job = FiscalJob::query()->first();
        $job->update(['status' => FiscalJob::STATUS_CLAIMED, 'claimed_at' => now()]);

        $this->postJson('/api/fiscal/applied', [
            'token' => $this->token,
            'results' => [[
                'id' => $job->id,
                'outcome' => 'success',
            ]],
        ])->assertOk();

        $this->assertSame('error', $tx->fresh()->fiscal_status);
        $this->assertSame(FiscalJob::STATUS_ERROR, $job->fresh()->status);
    }

    public function test_paper_copy_is_a_separate_job(): void
    {
        $tx = $this->makeDeposit();
        $job = FiscalJob::query()->first();

        try {
            app(FiscalGatewayService::class)->enqueuePrintCopy($tx->fresh());
            $this->fail('Бумажная копия до проведения не ставится.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, FiscalJob::query()->where('kind', FiscalJob::KIND_PRINT_COPY)->count());
        }

        app(FiscalGatewayService::class)->applyResults([[
            'id' => $job->id,
            'outcome' => 'success',
            'fn' => '9999078900001234',
            'fd' => '7',
            'fp' => '998877',
            'receipt_url' => 'https://ofd.example.test/r/7',
        ]]);

        $paper = app(FiscalGatewayService::class)->enqueuePrintCopy($tx->fresh());
        $again = app(FiscalGatewayService::class)->enqueuePrintCopy($tx->fresh());

        $this->assertSame($paper->id, $again->id);
        $this->assertSame(FiscalJob::KIND_PRINT_COPY, $paper->kind);
        $this->assertSame('7', $paper->payload['fd']);
        $this->assertNotSame((string) $job->external_id, (string) $paper->external_id);
        $this->assertSame('success', $tx->fresh()->fiscal_status);
    }

    public function test_stale_claim_returns_the_same_job(): void
    {
        $this->makeDeposit();
        $job = FiscalJob::query()->first();
        $externalId = (string) $job->external_id;

        $this->getJson('/api/fiscal/targets?token='.$this->token)->assertOk();
        $this->assertSame(FiscalJob::STATUS_CLAIMED, $job->fresh()->status);

        $job->forceFill(['claimed_at' => now()->subMinutes(10)])->save();
        app(FiscalGatewayService::class)->releaseStaleClaims();

        $job->refresh();
        $this->assertSame(FiscalJob::STATUS_PENDING, $job->status);
        $this->assertSame($externalId, (string) $job->external_id);
    }

    public function test_disabled_fiscal_does_not_queue(): void
    {
        config(['fiscal.enabled' => false]);

        $user = User::create([
            'name' => 'Off',
            'phone' => '+79990002002',
            'email' => 'fiscal-off@example.test',
            'password' => 'password',
        ]);

        $tx = Transaction::create([
            'user_id' => $user->id,
            'amount' => 50,
            'type' => 'deposit',
            'source' => 'card',
            'description' => 'Аванс',
        ]);

        $this->assertSame('skipped', $tx->fresh()->fiscal_status);
        $this->assertSame(0, FiscalJob::query()->count());

        $this->getJson('/api/fiscal/targets?token='.$this->token)
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }

    private function makeDeposit(): Transaction
    {
        $user = User::create([
            'name' => 'Fiscal Guest',
            'phone' => '+7999'.random_int(1000000, 9999999),
            'email' => 'fiscal-'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        $tx = Transaction::create([
            'user_id' => $user->id,
            'amount' => 100,
            'type' => 'deposit',
            'source' => 'card',
            'description' => 'Пополнение',
            'send_receipt' => false,
        ]);

        return $tx->fresh();
    }
}
