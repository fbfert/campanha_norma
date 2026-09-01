<x-layouts.app title="Pauta de resposta" breadcrumbs="Inicio / Pauta de resposta">
    @include('admin.pauta._aviso')

    <form method="get" class="card">
        <div class="grid grid-3">
            <div>
                <label for="from">De</label>
                <input id="from" name="from" type="date" value="{{ $from->toDateString() }}">
            </div>
            <div>
                <label for="to">Até</label>
                <input id="to" name="to" type="date" value="{{ $to->toDateString() }}">
            </div>
            <div>
                <label for="flow">Fluxo</label>
                <select id="flow" name="flow">
                    <option value="">Todos</option>
                    @foreach($flows as $flow)
                        <option value="{{ $flow->id }}" @selected($flowId === $flow->id)>{{ $flow->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="tema">Tema</label>
                <select id="tema" name="tema">
                    <option value="">Todos</option>
                    @foreach($temas as $tema)
                        <option value="{{ $tema->id }}" @selected(request()->integer('tema') === $tema->id)>{{ $tema->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="cidade">Cidade</label>
                <select id="cidade" name="cidade">
                    <option value="">Todas</option>
                    @foreach($cidades as $cidade)
                        <option value="{{ $cidade }}" @selected(request()->string('cidade')->toString() === $cidade)>{{ $cidade }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="estado">Situação</label>
                <select id="estado" name="estado">
                    <option value="">Todas</option>
                    <option value="pendente" @selected(request()->string('estado')->toString() === 'pendente')>Pendentes</option>
                    <option value="respondida" @selected(request()->string('estado')->toString() === 'respondida')>Respondidas</option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><x-icon name="search" size="16" /> Filtrar</button>
        </div>
    </form>

    <section class="card">
        <h2>{{ $pendentes }} pendente(s), {{ $respondidas }} respondida(s), {{ $total }} no total</h2>
        <p class="muted">
            A ordem é por relevância, e não por escassez: toda pessoa da fila é para responder, e a
            pontuação só decide quem vem antes. Nada é descartado por prioridade baixa.
        </p>

        @if($fila === [])
            <p class="muted">Nenhuma pessoa nesta combinação de filtros. Isso é ausência de registro, não falha do relatório.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Prioridade</th>
                            <th>Nome</th>
                            <th>Cidade</th>
                            <th>Tema</th>
                            <th>Urgência</th>
                            <th>O que escreveu</th>
                            <th>Situação</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($fila as $linha)
                            <tr>
                                <td>{{ $linha['priority'] }}</td>
                                <td>{{ $linha['name'] ?? '—' }}</td>
                                <td>{{ $linha['city'] ?? '—' }}</td>
                                <td>{{ $linha['topic'] ?? 'sem tema' }}</td>
                                <td>{{ $linha['urgency']?->label() ?? '—' }}</td>
                                <td>{{ $linha['excerpt'] }}</td>
                                <td>
                                    @if($linha['answered'])
                                        respondida
                                        @if($linha['answered_at'])
                                            em {{ $linha['answered_at']->format('d/m/Y') }}
                                        @endif
                                        @if($linha['answered_by'])
                                            (marcada por {{ $linha['answered_by'] }})
                                        @endif
                                    @else
                                        pendente
                                    @endif
                                </td>
                                <td><a class="btn ghost" href="{{ route('admin.pauta.show', $linha['insight_id']) }}">Abrir</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
