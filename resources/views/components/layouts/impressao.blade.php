<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trim(($title ?? '') . ' - ' . ($systemName ?? config('app.name')), ' -') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    Layout de impressão: sem menu, sem barra lateral, sem trilha e sem botões.

    O que sobra é capa e conteúdo. O PDF sai do próprio navegador, por
    `window.print()`, e por isso não há dependência nova nem nada carregado de
    fora: o sistema roda em servidor próprio e precisa abrir com internet ruim.

    O botão de imprimir some no papel — ele é a única coisa aqui que não faz
    parte do documento.
--}}
<body class="folha">
    @include('components.layouts.partials.icons')

    <div class="folha-conteudo">
        <div class="folha-acoes" x-data>
            <button class="btn" type="button" x-on:click="window.print()">
                <x-icon name="download" size="16" /> Imprimir ou salvar em PDF
            </button>
            <a class="btn ghost" href="{{ $voltar ?? url()->previous() }}">Voltar</a>
        </div>

        {{-- A capa é obrigatória, e o aviso de que isto não é pesquisa
             eleitoral registrada vai nela, não em rodapé: rodapé de página
             impressa não é lido, e um documento com números sobre opinião da
             população que circula sem essa frase é lido como pesquisa. --}}
        <section class="folha-capa">
            <h1>{{ $title ?? 'Relatório' }}</h1>
            <dl>
                <dt>Período</dt><dd>{{ $periodo }}</dd>
                <dt>Fluxo</dt><dd>{{ $fluxo ?? 'todos os fluxos' }}</dd>
                <dt>Respostas na amostra</dt><dd>{{ $amostra }}</dd>
                <dt>Gerado em</dt><dd>{{ now()->format('d/m/Y H:i') }}</dd>
                <dt>Gerado por</dt><dd>{{ auth()->user()?->name }}</dd>
            </dl>

            @if($nominal ?? false)
                <p class="alert alert-error">
                    <strong>Documento nominal.</strong> Traz nome, cidade e o que cada pessoa escreveu.
                    Não encaminhe, não publique e não compartilhe fora de quem precisa responder.
                </p>
            @endif

            <p class="folha-aviso">
                Este material é escuta de demanda. <strong>Não é pesquisa eleitoral registrada</strong>
                e não pergunta intenção de voto.
            </p>
        </section>

        {{ $slot }}
    </div>
</body>
</html>
