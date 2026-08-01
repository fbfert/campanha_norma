<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trim(($title ?? '') . ' - ' . ($systemName ?? config('app.name')), ' -') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    @include('components.layouts.partials.icons')
    <div class="app-shell">
        <aside class="sidebar">
            <h1>{{ $systemName ?? config('app.name') }}</h1>
            @include('components.layouts.partials.nav')
        </aside>
        <div class="main">
            <header class="topbar">
                <div>
                    <strong>{{ $title ?? 'Painel' }}</strong>
                    {{-- O mapa das trilhas vive em App\Support\Breadcrumbs, e não
                         aqui: dentro do layout ele não podia ser conferido por
                         teste, e treze telas ficaram sem entrada sem que ninguém
                         percebesse. --}}
                    <div class="muted breadcrumb-trail">
                        @foreach(\App\Support\Breadcrumbs::for($breadcrumbs ?? 'Início') as $migalha)
                            @if($migalha['rota'])
                                <a href="{{ route($migalha['rota']) }}">{{ $migalha['texto'] }}</a>
                            @else
                                <span>{{ $migalha['texto'] }}</span>
                            @endif
                            @if(! $loop->last)<span class="breadcrumb-sep"> / </span>@endif
                        @endforeach
                    </div>
                </div>
                <div class="actions">
                    <span>{{ auth()->user()->name }}</span>
                    <span class="muted">{{ auth()->user()->roles->pluck('name')->join(', ') }}</span>
                    <a class="btn ghost" href="{{ route('profile.show') }}"><x-icon name="user" size="16" />Perfil</a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn secondary" type="submit"><x-icon name="logout" size="16" />Sair</button>
                    </form>
                </div>
            </header>
            <main class="content">
                @include('components.flash')
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
