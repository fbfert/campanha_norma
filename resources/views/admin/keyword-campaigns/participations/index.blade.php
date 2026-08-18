<x-layouts.app title="Participantes" breadcrumbs="Inicio / Campanhas por palavra-chave / Participantes">
    <section class="card">
        <h2>{{ $campaign->name }}</h2>
        <p class="muted">
            {{ $participations->total() }} {{ $participations->total() === 1 ? 'inscrição encontrada' : 'inscrições encontradas' }}
            com os filtros atuais.
            @if($campaign->estaCongelada())
                A lista foi congelada em {{ $campaign->frozen_at->format('d/m/Y H:i') }} com
                {{ $campaign->frozen_list_count }} {{ $campaign->frozen_list_count === 1 ? 'participante' : 'participantes' }}:
                invalidar agora não muda a lista congelada nem um sorteio já executado.
            @endif
        </p>
        <div class="actions">
            <a class="btn ghost" href="{{ route('admin.keyword-campaigns.index') }}">Voltar às campanhas</a>
            @can('keyword_participations.export')
                <form method="post" action="{{ route('admin.keyword-campaigns.participations.export', $campaign) }}">
                    @csrf
                    <button class="btn ghost" type="submit"><x-icon name="download" size="16" />Exportar</button>
                </form>
            @endcan
        </div>
        @can('keyword_participations.export')
            <p class="muted">
                A exportação sai com o telefone mascarado, em arquivo privado que expira sozinho. O código de cupom
                nunca entra: uma planilha circula por muito mais gente do que a tela.
            </p>
        @endcan
    </section>

    <section class="card" style="margin-top:16px;">
        <form method="get" action="{{ route('admin.keyword-campaigns.participations.index', $campaign) }}">
            <div class="grid grid-2">
                <div>
                    <label for="busca">Nome ou telefone</label>
                    <input id="busca" name="busca" value="{{ $busca }}">
                </div>
                <div>
                    <label for="situacao">Situação</label>
                    <select id="situacao" name="situacao">
                        <option value="">Todas</option>
                        @foreach($situacoes as $situacao)
                            <option value="{{ $situacao->value }}" @selected($situacaoAtual === $situacao->value)>{{ $situacao->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-2" style="margin-top:12px;">
                <div>
                    <label for="elegibilidade">Elegibilidade</label>
                    <select id="elegibilidade" name="elegibilidade">
                        <option value="">Todas</option>
                        @foreach($elegibilidades as $elegibilidade)
                            <option value="{{ $elegibilidade->value }}" @selected($elegibilidadeAtual === $elegibilidade->value)>{{ $elegibilidade->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="sem_nome">Nome ausente</label>
                    <select id="sem_nome" name="sem_nome">
                        <option value="0" @selected(! $semNome)>Mostrar todos</option>
                        <option value="1" @selected($semNome)>Só quem está sem nome</option>
                    </select>
                    <p class="muted">Dá para chamar um ganhador pelo número, mas não dá para publicar sem nome.</p>
                </div>
            </div>
            <div class="actions" style="margin-top:12px;">
                <button class="btn" type="submit"><x-icon name="search" size="16" />Filtrar</button>
                <a class="btn ghost" href="{{ route('admin.keyword-campaigns.participations.index', $campaign) }}">Limpar</a>
            </div>
        </form>
    </section>

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Telefone</th>
                        <th>Palavra</th>
                        <th>Situação</th>
                        <th>Elegibilidade</th>
                        <th>Inscrito em</th>
                        @can('keyword_participations.invalidate')
                            <th>Ações</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse($participations as $participacao)
                        <tr>
                            <td>
                                {{ $participacao->displayName() ?? '—' }}
                                @if($participacao->reviewed_name && $participacao->captured_name)
                                    <br><span class="muted">o provedor informou "{{ $participacao->captured_name }}"</span>
                                @endif
                            </td>
                            <td>{{ $participacao->contact?->phone_normalized ?? '—' }}</td>
                            <td>{{ $participacao->matched_keyword }}</td>
                            <td>
                                {{ $participacao->status->label() }}
                                @if($participacao->invalidation_reason)
                                    <br><span class="muted">{{ $participacao->invalidation_reason }}</span>
                                @endif
                            </td>
                            <td>{{ $participacao->eligibility->label() }}</td>
                            <td>{{ $participacao->created_at?->format('d/m/Y H:i') }}</td>
                            @can('keyword_participations.invalidate')
                                {{-- Os formulários ficam dentro de um detalhe fechado.
                                     Abertos na célula, os dois campos de texto esticavam
                                     a coluna de ações para uns 400px e faziam a tabela
                                     inteira passar de mil — larga demais para caber em
                                     qualquer tela, não só nas pequenas. --}}
                                <td class="row-actions">
                                    <details>
                                        <summary>Ações</summary>
                                        <div class="row-actions-panel">
                                            <form method="post" action="{{ route('admin.keyword-campaigns.participations.name', [$campaign, $participacao]) }}">
                                                @csrf
                                                @method('put')
                                                <label for="reviewed_name_{{ $participacao->id }}">Corrigir nome</label>
                                                <input id="reviewed_name_{{ $participacao->id }}" name="reviewed_name"
                                                       value="{{ $participacao->displayName() }}" maxlength="120">
                                                <button class="btn ghost" type="submit">Salvar nome</button>
                                            </form>

                                            @if($participacao->status !== \App\Enums\KeywordParticipationStatus::Invalidada)
                                                <form method="post" action="{{ route('admin.keyword-campaigns.participations.invalidate', [$campaign, $participacao]) }}">
                                                    @csrf
                                                    @method('put')
                                                    <label for="motivo_{{ $participacao->id }}">Motivo da invalidação</label>
                                                    <input id="motivo_{{ $participacao->id }}" name="invalidation_reason" required minlength="5" maxlength="500">
                                                    <button class="btn secondary" type="submit">Invalidar</button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="7">Nenhuma inscrição encontrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $participations->links() }}
    </section>
</x-layouts.app>
