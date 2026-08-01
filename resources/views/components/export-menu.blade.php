@props(['route', 'params' => []])

{{--
    Menu de exportação.

    Quatro formatos viram quatro botões lado a lado, e uma barra de ações com
    seis botões deixa de ter ação principal — tudo compete com tudo. Recolhidos
    atrás de um só, a tela volta a ter uma coisa evidente a fazer.

    E um `<details>` nativo, como os grupos do menu lateral: abre e fecha,
    responde ao teclado e e anunciado por leitor de tela sem uma linha de
    JavaScript. Se o script for bloqueado, continua funcionando.

    Os formatos e o que cada um serve estão descritos aqui e não na tela que
    chama, para que as duas telas digam a mesma coisa.
--}}
@php
    $formats = [
        ['format' => 'csv', 'label' => 'CSV', 'hint' => 'Abre em qualquer planilha'],
        ['format' => 'xlsx', 'label' => 'Excel', 'hint' => 'Planilha já formatada'],
        ['format' => 'markdown', 'label' => 'Markdown', 'hint' => 'Para colar em documentação'],
        ['format' => 'sql', 'label' => 'SQL', 'hint' => 'Para recriar em outra instalação'],
    ];
@endphp

<details class="export-menu">
    <summary class="btn secondary">
        <x-icon name="download" size="16" />Exportar
    </summary>
    <div class="export-menu-panel">
        @foreach($formats as $item)
            <a href="{{ route($route, $params + ['format' => $item['format']]) }}">
                <strong>{{ $item['label'] }}</strong>
                <span class="muted">{{ $item['hint'] }}</span>
            </a>
        @endforeach
    </div>
</details>
