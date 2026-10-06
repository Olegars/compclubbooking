<?php

namespace App\Http\Controllers;

use App\Mcp\McpActor;
use App\Mcp\McpServer;
use App\Models\McpToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpController extends Controller
{
    public function handle(Request $request, McpServer $server): Response
    {
        if (! config('mcp.enabled') || ! config('mcp.http_enabled')) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if (! $this->originAllowed($request)) {
            return response()->json(['error' => 'origin'], 403);
        }
        if (strlen($request->getContent()) > 262144) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Слишком большой запрос'],
            ], 413);
        }

        $token = McpToken::authenticate((string) $request->bearerToken());
        $actor = $token ? McpActor::fromToken($token) : null;
        if (! $actor) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ], 400);
        }

        $response = $server->dispatch($payload, $actor);
        if ($response === null) {
            return response('', 202);
        }

        return response()->json($response);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return true;
        }
        $allowed = [rtrim((string) config('app.url'), '/')];
        foreach ((array) config('mcp.allowed_origins', []) as $extra) {
            $allowed[] = rtrim((string) $extra, '/');
        }

        return in_array(rtrim($origin, '/'), array_filter($allowed), true);
    }
}
