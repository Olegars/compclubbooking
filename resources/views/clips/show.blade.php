<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Клип · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; background: #050505; color: #eee; font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; }
        video { width: min(960px, 94vw); background: #000; border-radius: 12px; }
        p { color: #666; font-size: 12px; letter-spacing: .2em; text-transform: uppercase; }
    </style>
</head>
<body>
    <div>
        <p>Instant Replay</p>
        <video src="{{ $url }}" controls playsinline></video>
    </div>
</body>
</html>
