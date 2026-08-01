@props([
    'plan',
    'stored',
    'previewRoute',
    'confirmRoute',
    'exportRoute',
    'backRoute',
    'columns',
    'ignored' => [],
    'labelSingular',
    'labelPlural',
])

{{--
    Painel de importação em duas fases.

    A primeira tela so recebe o arquivo. A segunda mostra, linha por linha, o
    que vai acontecer — e so ali existe o botão que grava. Quem envia uma
    planilha não sabe o que ela vai fazer até ver escrito.

    O modelo a baixar e a própria exportação. Não existe um arquivo de modelo
    separado, que sairia de sincronia com o que o sistema aceita na primeira vez
    que uma coluna mudasse.
--}}
<section class="card">
    <h2>Importar {{ $labelPlural }}</h2>
    <p class="muted">
        Envie um CSV, uma planilha do Excel ou um Markdown. Nada e gravado agora: a próxima tela
        mostra o que será criado, o que será alterado e o que será recusado, e so então você confirma.
    </p>

    <div class="alert">
        <x-icon name="info" size="18" />
        <span>
            O caminho mais seguro e <strong>exportar primeiro</strong>, editar a planilha e
            devolvê-la. A exportação já vem com as colunas certas.
        </span>
    </div>

    <form method="post" action="{{ route($previewRoute) }}" enctype="multipart/form-data">
        @csrf
        <p>
            <label for="file">Arquivo</label>
            <input id="file" name="file" type="file" accept=".csv,.txt,.xlsx,.md,.markdown" required>
        </p>
        @error('file')<p class="alert error"><x-icon name="alert" size="18" />{{ $message }}</p>@enderror
        <div class="actions">
            <button class="btn" type="submit"><x-icon name="upload" size="16" />Conferir arquivo</button>
            <a class="btn secondary" href="{{ route($exportRoute) }}"><x-icon name="download" size="16" />Baixar o modelo</a>
            <a class="btn ghost" href="{{ route($backRoute) }}">Voltar</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Como o arquivo e lido</h3>
    <ul class="muted">
        <li>A coluna <code>identificador</code> decide: se já existe, a linha <strong>atualiza</strong>; se não, <strong>cria</strong>.</li>
        <li>Sem identificador, ele e derivado do nome.</li>
        <li><strong>Nada e apagado.</strong> {{ ucfirst($labelSingular) }} que sumiu da planilha continua no sistema — excluir e sempre pela tela, uma por vez.</li>
        <li>Colunas lidas: {!! collect($columns)->map(fn ($c) => '<code>'.e($c).'</code>')->join(', ') !!}.</li>
        @if($ignored !== [])
            <li>Colunas ignoradas de propósito: {!! collect($ignored)->map(fn ($c) => '<code>'.e($c).'</code>')->join(', ') !!}.</li>
        @endif
        <li>Em Markdown, o arquivo pode ter títulos e parágrafos ao redor: so a tabela e lida.</li>
        <li>Arquivo <code>.sql</code> não e aceito aqui. Ele existe para ser levado ao banco de destino conscientemente, com as próprias credenciais.</li>
    </ul>
</section>

@if($plan !== null)
    @php
        $contagem = collect($plan)->countBy('acao');
    @endphp

    <section class="card">
        <h2>Confira antes de gravar</h2>

        <div class="manual-facts">
            <div><span class="muted">Criar</span><strong>{{ $contagem['criar'] ?? 0 }}</strong></div>
            <div><span class="muted">Atualizar</span><strong>{{ $contagem['atualizar'] ?? 0 }}</strong></div>
            <div><span class="muted">Recusadas</span><strong>{{ $contagem['erro'] ?? 0 }}</strong></div>
        </div>

        @if(($contagem['erro'] ?? 0) > 0)
            <div class="alert warning">
                <x-icon name="alert" size="18" />
                <span>
                    As linhas recusadas são simplesmente puladas. O resto do arquivo e gravado
                    normalmente — corrija e envie de novo so o que faltou.
                </span>
            </div>
        @endif

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Linha</th><th>{{ ucfirst($labelSingular) }}</th><th>Identificador</th><th>O que vai acontecer</th></tr>
                </thead>
                <tbody>
                    @foreach($plan as $item)
                        <tr>
                            <td>{{ $item['linha'] }}</td>
                            <td>{{ $item[$labelSingular] ?? '-' }}</td>
                            <td><code>{{ $item['identificador'] }}</code></td>
                            <td>
                                @if($item['acao'] === 'erro')
                                    <strong style="color:var(--danger);">Recusada</strong>
                                    <span class="muted">{{ $item['motivo'] }}</span>
                                @elseif($item['acao'] === 'criar')
                                    Criar
                                @else
                                    Atualizar
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if((($contagem['criar'] ?? 0) + ($contagem['atualizar'] ?? 0)) > 0)
            <form method="post" action="{{ route($confirmRoute) }}" style="margin-top:16px;"
                onsubmit="return confirm('Gravar as alterações mostradas acima?')">
                @csrf
                <input type="hidden" name="stored" value="{{ $stored }}">
                <button class="btn" type="submit"><x-icon name="check" size="16" />Confirmar e gravar</button>
            </form>
        @else
            <p class="muted" style="margin-top:16px;">Nenhuma linha aproveitável neste arquivo.</p>
        @endif
    </section>
@endif
