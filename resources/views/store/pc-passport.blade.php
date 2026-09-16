<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070705">
    <title>Паспорт ПК · {{ $passport['serial'] ?? '' }}</title>
    <style>
        :root {
            --bg: #070705;
            --card: #10100c;
            --line: rgba(251, 191, 36, .18);
            --amber: #fbbf24;
            --muted: #8a8578;
            --ok: #4ade80;
            --warn: #fb923c;
            --bad: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: #f5f0e6;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            min-height: 100vh;
            padding: 20px 16px 40px;
        }
        .wrap { max-width: 520px; margin: 0 auto; }
        .eyebrow {
            font-size: 10px;
            letter-spacing: .28em;
            text-transform: uppercase;
            color: var(--amber);
            font-weight: 800;
        }
        h1 {
            margin: 6px 0 4px;
            font-size: 26px;
            letter-spacing: -.03em;
            line-height: 1.15;
        }
        .sn { font-family: ui-monospace, Consolas, monospace; color: var(--muted); font-size: 13px; }
        .badge {
            display: inline-block;
            margin-top: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .04em;
        }
        .badge.active { background: rgba(74,222,128,.12); color: var(--ok); }
        .badge.expiring { background: rgba(251,146,60,.12); color: var(--warn); }
        .badge.expired, .badge.closed, .badge.claimed { background: rgba(248,113,113,.12); color: var(--bad); }
        .badge.none { background: rgba(255,255,255,.06); color: var(--muted); }
        .card {
            margin-top: 18px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
        }
        .card h2 {
            margin: 0 0 12px;
            font-size: 11px;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--muted);
        }
        video {
            width: 100%;
            border-radius: 12px;
            background: #000;
            max-height: 52vh;
        }
        .hint { color: var(--muted); font-size: 13px; line-height: 1.45; margin: 0; }
        .tl { list-style: none; margin: 0; padding: 0; }
        .tl li {
            position: relative;
            padding: 0 0 16px 18px;
            border-left: 1px solid rgba(251,191,36,.28);
        }
        .tl li:last-child { padding-bottom: 0; border-left-color: transparent; }
        .tl li::before {
            content: "";
            position: absolute;
            left: -5px; top: 4px;
            width: 9px; height: 9px;
            border-radius: 50%;
            background: var(--amber);
        }
        .tl .when { font-size: 11px; color: var(--muted); }
        .tl .what { font-size: 15px; font-weight: 700; margin-top: 2px; }
        .tl .det { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .part {
            display: grid;
            gap: 4px;
            padding: 12px 0;
            border-top: 1px solid rgba(255,255,255,.06);
        }
        .part:first-of-type { border-top: 0; padding-top: 0; }
        .part .type { font-size: 10px; letter-spacing: .16em; text-transform: uppercase; color: var(--amber); font-weight: 800; }
        .part .name { font-size: 14px; font-weight: 650; }
        .part .serials { font-family: ui-monospace, Consolas, monospace; font-size: 12px; color: #d6d0c2; word-break: break-all; }
        .foot { margin-top: 22px; color: #5c584e; font-size: 11px; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="eyebrow">QR-паспорт · Digital Twin</div>
        <h1>{{ $passport['title'] }}</h1>
        <div class="sn">S/N {{ $passport['serial'] ?: '—' }} · {{ $passport['club'] }}</div>
        @if(!empty($passport['assembler']))
            <div class="sn" style="margin-top:6px">Собрал {{ $passport['assembler'] }}</div>
        @endif
        @if(!empty($passport['warranty_label']))
            <span class="badge {{ $passport['warranty_state'] }}">{{ $passport['warranty_label'] }}</span>
        @endif

        <section class="card">
            <h2>Видео сборки</h2>
            @if(!empty($passport['video']['ready']) && !empty($passport['video']['url']))
                <video src="{{ $passport['video']['url'] }}" controls playsinline preload="metadata"></video>
                <p class="hint" style="margin-top:10px">{{ $passport['video']['hint'] }}</p>
            @else
                <p class="hint">{{ $passport['video']['hint'] ?? '' }}</p>
            @endif
        </section>

        <section class="card">
            <h2>Таймлайн</h2>
            @if(!empty($passport['timeline']))
                <ol class="tl">
                    @foreach($passport['timeline'] as $event)
                        <li>
                            @if(!empty($event['at']))
                                <div class="when">{{ $event['at'] }}</div>
                            @endif
                            <div class="what">{{ $event['title'] }}</div>
                            @if(!empty($event['detail']))
                                <div class="det">{{ $event['detail'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="hint">История сборки появится после выдачи ПК.</p>
            @endif
        </section>

        <section class="card">
            <h2>Комплектующие и гарантия</h2>
            @forelse($passport['parts'] as $part)
                <div class="part">
                    <div class="type">{{ $part['type_label'] }}</div>
                    <div class="name">{{ $part['name'] }}</div>
                    @if(!empty($part['serial_label']) || !empty($part['serials']))
                        <div class="serials">S/N {{ $part['serial_label'] ?: implode(' · ', $part['serials']) }}</div>
                    @endif
                    @if(!empty($part['warranty_label']))
                        <span class="badge {{ $part['warranty_state'] }}">{{ $part['warranty_label'] }}</span>
                    @endif
                    @if(!empty($part['in_repair']))
                        <span class="badge claimed">{{ $part['repair_label'] ?: 'В ремонте' }}</span>
                    @endif
                </div>
            @empty
                <p class="hint">Комплектация не записана.</p>
            @endforelse
        </section>

        <p class="foot">Гарантийный QR на корпусе · {{ $passport['club'] }}</p>
    </div>
</body>
</html>
