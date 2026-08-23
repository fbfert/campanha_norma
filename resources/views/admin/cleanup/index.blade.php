<x-layouts.app title="Limpeza" breadcrumbs="Inicio / Limpeza">
    <section class="card">
        <h2>Limpeza</h2>
        <p class="muted">
            Remove a participação de uma pessoa nas funções que o sistema já executou com ela — inscrição em campanha,
            conversa, presença em lote de envio, etiqueta, histórico. O cadastro do contato não é apagado aqui: para isso
            existe a tela de <a href="{{ route('admin.contacts.index') }}">Contatos</a>.
        </p>
        <p class="muted">
            O que sai daqui some na hora de todo o resto do sistema — painel da pesquisa, relatórios, contagem de lote e
            lista de sorteio deixam de enxergar a pessoa no mesmo instante. Fica <strong>{{ $diasNaLixeira }}</strong>
            {{ $diasNaLixeira === 1 ? 'dia' : 'dias' }} na lixeira, de onde volta inteiro; passado o prazo, é apagado em
            definitivo e não volta mais.
        </p>
        <div class="actions">
            <a class="btn secondary" href="{{ route('admin.cleanup.trash') }}">
                <x-icon name="trash" size="16" />Lixeira
                @if($naLixeira > 0)<span class="badge">{{ $naLixeira }}</span>@endif
            </a>
        </div>
    </section>

    <section class="card">
        <h2>Procurar a pessoa</h2>
        <form method="get" action="{{ route('admin.cleanup.index') }}">
            <p>
                <label for="busca">Nome ou telefone</label>
                <input id="busca" name="busca" value="{{ $termo }}" autofocus>
            </p>
            <button class="btn" type="submit"><x-icon name="search" size="16" />Procurar</button>
        </form>
    </section>

    @if($termo !== '')
        <section class="card">
            <h2>Resultado</h2>
            @forelse($contatos as $contato)
                @if($loop->first)
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>Nome</th><th>Telefone</th><th>Situação</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                @endif
                            <tr>
                                <td>{{ $contato->name }}</td>
                                <td>{{ $contato->phone ?? $contato->phone_normalized }}</td>
                                <td>{{ $contato->status?->label() }}</td>
                                <td>
                                    <a class="btn secondary" href="{{ route('admin.cleanup.show', $contato) }}">
                                        Ver participações
                                    </a>
                                </td>
                            </tr>
                @if($loop->last)
                            </tbody>
                        </table>
                    </div>
                @endif
            @empty
                <div class="empty-state">
                    <x-icon name="search" size="32" />
                    <p>Nenhum contato cadastrado bate com “{{ $termo }}”.</p>
                    <p class="muted">
                        A Limpeza só age sobre contato cadastrado. Número que nunca virou contato não tem participação
                        para remover aqui.
                    </p>
                </div>
            @endforelse
        </section>
    @endif
</x-layouts.app>
