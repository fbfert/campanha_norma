<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $systemName ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main style="min-height:100vh;display:grid;place-items:center;padding:24px;">
        <section class="card" style="width:min(440px,100%);">
            <h1 style="margin-top:0;">{{ $systemName ?? config('app.name') }}</h1>
            @include('components.flash')
            {{ $slot }}
        </section>
    </main>
</body>
</html>
