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
        {{-- Estado da gaveta de navegação. Caixa de seleção, e não script:
             o sistema precisa abrir com a internet ruim, e menu que depende
             de JavaScript é menu que às vezes não abre. No desktop a barra
             está sempre à vista e isto não faz nada. --}}
        <input class="nav-switch" type="checkbox" id="menu-principal">
        <aside class="sidebar" id="navegacao-principal">
            <h1>{{ $systemName ?? config('app.name') }}</h1>
            @include('components.layouts.partials.nav')
        </aside>
        {{-- Tocar fora fecha a gaveta. --}}
        <label class="nav-scrim" for="menu-principal" aria-hidden="true"></label>
        <div class="main">
            <header class="topbar">
                <label class="btn ghost nav-toggle" for="menu-principal" aria-controls="navegacao-principal">
                    <x-icon name="layers" size="16" />Menu
                </label>
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
                    {{-- O aviso fica no topo, e não só no painel: pendência de
                         resposta não espera alguém passar pelo painel. Cada hora
                         parada é uma hora de silêncio para quem escreveu. --}}
                    @if(($inboundPendingCount ?? 0) > 0)
                        <a class="btn secondary" href="{{ route('admin.inbound-attendance.index') }}">
                            <x-icon name="bell" size="16" />
                            {{ $inboundPendingCount }} {{ $inboundPendingCount === 1 ? 'mensagem aguarda resposta' : 'mensagens aguardam resposta' }}
                        </a>
                    @endif
                    {{-- Identificação, não ação: sai da tela pequena, onde
                         ocupava duas linhas e empurrava os botões para baixo.
                         O nome continua na tela de perfil. --}}
                    <span class="topbar-identity">{{ auth()->user()->name }}</span>
                    <span class="muted topbar-identity">{{ auth()->user()->roles->pluck('name')->join(', ') }}</span>
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
