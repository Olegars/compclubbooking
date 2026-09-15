<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Клип · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; background: #050505; color: #eee; font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; }
        .box { display: grid; justify-items: center; gap: 12px; padding: 24px; }
        video { width: min({{ ($clip->aspect ?? '') === '9:16' ? '420px' : '960px' }}, 94vw); max-height: 88vh; background: #000; border-radius: 12px; }
        p { color: #666; font-size: 12px; letter-spacing: .2em; text-transform: uppercase; margin: 0; }
        a { color: #a3a3a3; font-size: 13px; }
        .book { display: inline-block; margin-top: 4px; padding: 10px 18px; border-radius: 999px; background: #22c55e; color: #050505; font-weight: 800; font-size: 12px; letter-spacing: .12em; text-transform: uppercase; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <p>{{ ($clip->aspect ?? '') === '9:16' ? 'Reels / Shorts' : 'Instant Replay' }}</p>
        <video src="{{ $url }}" controls playsinline></video>
        @if(!empty($clip->share_token))
            <a href="{{ $clip->shareUrl() }}">Ссылка на клип</a>
        @endif
        <a class="book" href="{{ $book_url ?? url('/') }}">Забронировать ПК</a>
    </div>
</body>
</html>
