<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wi-Fi · Sector</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background: #050505; color: #e5e5e5;
        }
        .card {
            width: min(420px, 92vw); padding: 2rem; border: 1px solid rgba(255,255,255,.08);
            border-radius: 1rem; background: #0a0a0a;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; letter-spacing: .08em; text-transform: uppercase; color: #22d3ee; }
        p { margin: .4rem 0; font-size: .8rem; color: rgba(255,255,255,.45); line-height: 1.45; }
        .ok { color: #4ade80; }
        .err { color: #f87171; }
        .warn { color: #fbbf24; }
        button, a.btn {
            display: inline-block; margin-top: 1.25rem; padding: .85rem 1.25rem;
            border: 0; border-radius: .75rem; background: #06b6d4; color: #000;
            font-weight: 800; font-size: .7rem; letter-spacing: .12em; text-transform: uppercase;
            text-decoration: none; cursor: pointer;
        }
        button:disabled { opacity: .4; cursor: not-allowed; }
        .meta { margin-top: 1rem; font-size: .65rem; color: rgba(255,255,255,.25); word-break: break-all; }
    </style>
</head>
<body>
<div class="card">
    <h1>Гостевой Wi-Fi</h1>

    @if (! $enabled)
        <p class="warn">Доступ временно выключен (WIFI_ACCESS_ENABLED).</p>
    @elseif (! $authenticated)
        <p>Войдите по телефону, затем откроется интернет через точку клуба.</p>
        <a class="btn" href="{{ $loginUrl }}">Войти</a>
    @else
        <p>Вы: <span class="ok">{{ $user->phone ?: $user->name }}</span></p>
        <p>Станция: <code>{{ $station }}</code></p>
        <button type="button" id="go" @disabled(!$enabled)>Открыть интернет</button>
        <p id="msg" class="meta"></p>
    @endif

    <div class="meta">
        @if ($mac) MAC: {{ $mac }}<br>@endif
        @if ($ip) IP: {{ $ip }}@endif
    </div>
</div>

@if ($enabled && $authenticated)
<script>
(() => {
    const btn = document.getElementById('go');
    const msg = document.getElementById('msg');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    btn?.addEventListener('click', async () => {
        btn.disabled = true;
        msg.textContent = 'Запрос…';
        msg.className = 'meta';
        try {
            const res = await fetch(@json(url('/api/wifi/authorize')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    station: @json($station),
                    mac: @json($mac ?: null),
                    ip: @json($ip ?: null),
                }),
            });
            const data = await res.json();
            if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || ('HTTP ' + res.status));
            }
            msg.textContent = data.message || 'OK';
            msg.className = 'meta ok';
        } catch (e) {
            msg.textContent = e.message || String(e);
            msg.className = 'meta err';
            btn.disabled = false;
        }
    });
})();
</script>
@endif
</body>
</html>
