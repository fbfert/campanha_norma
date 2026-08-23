<x-layouts.app title="Lixeira da Limpeza" breadcrumbs="Inicio / Limpeza / Lixeira">
    <section class="card">
        <h2>Lixeira da Limpeza</h2>
        <p class="muted">
            Cada linha é uma limpeza executada. Restaurar devolve tudo o que ela tirou do ar, de uma vez — as
            participações voltam a contar em painel, relatório e sorteio no mesmo instante em que voltam aqui.
        </p>
        <p class="muted">
            O prazo é de <strong>{{ $diasNaLixeira }}</strong> {{ $diasNaLixeira === 1 ? 'dia' : 'dias' }}, ajustável em
            <a href="{{ route('admin.settings.edit') }}">Configurações</a>. Vencido o prazo, o expurgo apaga em definitivo
            e o botão de restaurar deixa de existir.
        </p>
    </section>

    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Contato</th>
                        <th>O que saiu</th>
                        <th>Motivo</th>
                        <th>Quem e quando</th>
                        <th>Situação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($limpezas as $operacao)
                        <tr>
                            <td>
                                {{ $operacao->contact_name_snapshot ?? 'contato removido' }}
                                <br><span class="muted">{{ $operacao->contact_phone_snapshot }}</span>
                            </td>
                            <td>
                                {{ $operacao->items_count }} {{ $operacao->items_count === 1 ? 'item' : 'itens' }}
                                @if($operacao->involved_draw)
                                    <br><span class="muted">envolveu inscrição sorteada</span>
                                @endif
                                <details class="row-actions">
                                    <summary>ver itens</summary>
                                    <ul class="stack-list">
                                        @foreach($operacao->items as $item)
                                            <li>{{ $item->summary }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            </td>
                            <td>{{ $operacao->reason }}</td>
                            <td>
                                {{ $operacao->executor?->name ?? 'usuário removido' }}
                                <br><span class="muted">{{ $operacao->executed_at->format('d/m/Y H:i') }}</span>
                            </td>
                            <td>
                                @if($operacao->restored_at)
                                    restaurada por {{ $operacao->restorer?->name ?? 'usuário removido' }}
                                    em {{ $operacao->restored_at->format('d/m/Y H:i') }}
                                @elseif($operacao->purged_at)
                                    apagada em definitivo em {{ $operacao->purged_at->format('d/m/Y H:i') }}
                                @else
                                    na lixeira até {{ $operacao->expires_at->format('d/m/Y H:i') }}
                                @endif
                            </td>
                            <td>
                                @if($operacao->podeRestaurar())
                                    @can('cleanup.restore')
                                        <form method="post" action="{{ route('admin.cleanup.restore', $operacao) }}">
                                            @csrf
                                            <label class="checkbox">
                                                <input type="checkbox" name="confirm" value="1" required>
                                                Confirmo restaurar
                                            </label>
                                            <button class="btn secondary" type="submit">
                                                <x-icon name="refresh" size="16" />Restaurar
                                            </button>
                                        </form>
                                    @endcan
                                @else
                                    <span class="muted">sem volta</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <x-icon name="trash" size="32" />
                                    <p>Nenhuma limpeza foi executada ainda.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $limpezas->links() }}
    </section>
</x-layouts.app>
