<?php

namespace Tests\Feature;

use App\Mcp\McpActor;
use App\Mcp\McpServer;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerMcpConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_receives_an_agent_file_and_cannot_read_the_hall(): void
    {
        config(['app.url' => 'https://0451.space', 'mcp.enabled' => true, 'mcp.http_enabled' => true]);
        $user = $this->player();

        $response = $this->actingAs($user)->postJson('/account/profile/mcp', ['agent' => 'cursor']);

        $response->assertOk()
            ->assertJsonPath('title', 'Cursor')
            ->assertJsonPath('filename', 'mcp.json')
            ->assertJsonPath('ready', true);
        $config = (string) $response->json('config');
        $this->assertStringContainsString('https://0451.space/mcp', $config);
        $this->assertStringContainsString('"Authorization": "Bearer mcp_', $config);
        $this->assertStringContainsString('%USERPROFILE%\\.cursor\\mcp.json', (string) $response->json('where.0'));

        preg_match('/Bearer (mcp_[A-Za-z0-9]+)/', $config, $match);
        $plain = $match[1] ?? '';
        $this->assertNotSame('', $plain);
        $stored = McpToken::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(hash('sha256', $plain), $stored->token_hash);
        $this->assertNotNull($stored->expires_at);
        $this->assertNull($stored->admin_id);

        $actor = McpActor::fromToken(McpToken::authenticate($plain));
        $this->assertNotNull($actor);
        $denied = app(McpServer::class)->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/read',
            'params' => ['uri' => 'club://status/summary'],
        ], $actor);
        $this->assertSame(-32001, $denied['error']['code'] ?? null);
    }

    public function test_a_second_click_revokes_the_previous_player_token(): void
    {
        $user = $this->player();

        $first = $this->actingAs($user)->postJson('/account/profile/mcp', ['agent' => 'claude'])->assertOk();
        preg_match('/Bearer (mcp_[A-Za-z0-9]+)/', (string) $first->json('config'), $match);
        $old = McpToken::authenticate($match[1]);
        $this->assertNotNull($old);

        $this->actingAs($user)->postJson('/account/profile/mcp', ['agent' => 'claude'])->assertOk();

        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNull(McpToken::authenticate($match[1]));
        $this->assertSame(1, McpToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_unknown_agent_and_guest_are_rejected(): void
    {
        $this->postJson('/account/profile/mcp', ['agent' => 'cursor'])->assertUnauthorized();

        $this->actingAs($this->player())
            ->postJson('/account/profile/mcp', ['agent' => 'staff'])
            ->assertStatus(422);
    }

    private function player(): User
    {
        return User::create([
            'name' => 'Nova',
            'phone' => '+7999'.random_int(1000000, 9999999),
            'email' => 'player.'.uniqid().'@example.test',
            'password' => 'password',
        ]);
    }
}
