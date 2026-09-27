<?php

namespace App\Services\Faceit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Data API и Connect. Ключ и секрет только здесь. Токен гостя наружу не отдаём.
 */
class FaceitClient
{
    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function oauthConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function apiKey(): string
    {
        return trim((string) config('services.faceit.api_key'));
    }

    public function clientId(): string
    {
        return trim((string) config('services.faceit.client_id'));
    }

    public function clientSecret(): string
    {
        return trim((string) config('services.faceit.client_secret'));
    }

    public function redirectUri(): string
    {
        $set = trim((string) config('services.faceit.redirect'));

        return $set !== '' ? $set : url('/account/faceit/callback');
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function authorizeUrl(string $state, string $challenge, array $query = []): string
    {
        $base = rtrim((string) config('services.faceit.authorize_url'), '?');

        return $base.'?'.http_build_query(array_merge([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $response = Http::asForm()->timeout(12)->post((string) config('services.faceit.token_url'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code_verifier' => $verifier,
        ]);

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function userinfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(12)
            ->acceptJson()
            ->get((string) config('services.faceit.userinfo_url'));

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function player(string $playerId): array
    {
        return $this->data('GET', '/players/'.rawurlencode($playerId));
    }

    /**
     * @return array<string, mixed>
     */
    public function bans(string $playerId): array
    {
        return $this->data('GET', '/players/'.rawurlencode($playerId).'/bans');
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(string $playerId): array
    {
        return $this->data('GET', '/players/'.rawurlencode($playerId).'/stats/cs2');
    }

    /**
     * @return array<string, mixed>
     */
    public function hub(string $hubId): array
    {
        return $this->data('GET', '/hubs/'.rawurlencode($hubId));
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $matchId): array
    {
        return $this->data('GET', '/matches/'.rawurlencode($matchId));
    }

    /**
     * @return array<string, mixed>
     */
    public function championshipResults(string $championshipId): array
    {
        return $this->data('GET', '/championships/'.rawurlencode($championshipId).'/results');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $method, string $path): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new \RuntimeException('FACEIT_API_KEY не задан');
        }
        $url = rtrim((string) config('services.faceit.data_url'), '/').$path;
        $pending = Http::withToken($key)->timeout(12)->acceptJson();
        $response = strtoupper($method) === 'GET' ? $pending->get($url) : $pending->send($method, $url);

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if ($response->status() === 429) {
            throw new FaceitRateLimited('FACEIT 429');
        }
        if ($response->failed()) {
            throw new \RuntimeException('FACEIT HTTP '.$response->status());
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
