<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#050505" />

    <title inertia>{{ config('club.seo.title') }}</title>
    <meta name="description" content="{{ config('club.seo.description') }}" />

    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="{{ config('club.seo.title') }}" />
    <meta property="og:title" content="{{ config('club.seo.title') }}" />
    <meta property="og:description" content="{{ config('club.seo.description') }}" />
    <meta property="og:url" content="{{ url()->current() }}" />
    <meta name="twitter:card" content="summary_large_image" />

    @routes @vite(['resources/js/app.js', 'resources/css/app.css'])
    @inertiaHead
</head>
<body>
@inertia
</body>
</html>
