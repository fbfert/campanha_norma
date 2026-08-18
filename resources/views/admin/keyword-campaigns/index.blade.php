<x-layouts.app title="Campanhas por palavra-chave" breadcrumbs="Inicio / Campanhas por palavra-chave">
    <section class="card">
        <h2>Campanhas por palavra-chave</h2>
        <p class="muted">
            Quem escrever uma das palavras dentro da vigência vira inscrito, com o contato criado quando o número é
            desconhecido e a mensagem de origem guardada como prova. Sem campanha ativa, nada dispara.
        </p>
        <p class="muted">
            As confirmações saem sob um teto global de <strong>{{ $tetoPorMinuto }}</strong> por minuto, com
            <strong>{{ $intervaloMinimo }}s</strong> entre uma e outra. O excedente é adiado, nunca descartado:
            responder centenas de mensagens no ritmo da fila é o que mais rápido derruba o número.
        </p>
        @can('keyword_campaigns.manage')
            <div class="actions">
                <a class="btn" href="{{ route('admin.keyword-campaigns.create') }}"><x-icon name="plus" size="16" />Nova campanha</a>
            </div>
        @endcan
    </section>

    @if(session('avisos'))
        <div class="alert warning" style="margin-top:16px;">
            <strong>Confira as palavras antes de divulgar:</strong>
            <ul>
                @foreach(session('avisos') as $aviso)
                    <li>{{ $aviso }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Situação</th>
                        <th>Vigência</th>
                        <th>Palavras</th>
                        <th>Inscritos</th>
                        <th>A conferir</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaigns as $campanha)
                        <tr>
                            <td>
                                {{ $campanha->name }}
                                @if($campanha->description)
                                    <br><span class="muted">{{ $campanha->description }}</span>
                                @endif
                                @if($campanha->hourly_alert_raised_at)
                                    <br><span class="muted">alarme de rajada em {{ $campanha->hourly_alert_raised_at->format('d/m/Y H:i') }}</span>
                                @endif
                            </td>
                            <td>
                                {{ $campanha->status->label() }}
                                @if($campanha->estaVigente())
                                    <br><span class="muted">recebendo inscrições</span>
                                @endif
                                @if($campanha->estaCongelada())
                                    <br><span class="muted">lista congelada com {{ $campanha->frozen_list_count }}</span>
                                @endif
                            </td>
                            <td>
                                {{ $campanha->starts_at?->format('d/m/Y H:i') }}
                                <br><span class="muted">até {{ $campanha->ends_at?->format('d/m/Y H:i') }}</span>
                            </td>
                            <td>{{ implode(', ', $campanha->keywordList()) }}</td>
                            <td>
                                {{ $campanha->enrolled_count }}@if($campanha->participant_limit)<span class="muted"> / {{ $campanha->participant_limit }}</span>@endif
                                @if($campanha->ambiguous_count)
                                    <br><span class="muted">{{ $campanha->ambiguous_count }} em revisão</span>
                                @endif
                            </td>
                            <td>{{ $campanha->pending_review_count }}</td>
                            <td class="actions">
                                @can('keyword_participations.view')
                                    <a class="btn ghost" href="{{ route('admin.keyword-campaigns.participations.index', $campanha) }}">Participantes</a>
                                    <a class="btn ghost" href="{{ route('admin.keyword-campaigns.eligibility.index', $campanha) }}">Conferência</a>
                                    <a class="btn ghost" href="{{ route('admin.keyword-campaigns.draws.index', $campanha) }}">Sorteio</a>
                                @endcan
                                @can('keyword_campaigns.manage')
                                    @unless($campanha->estaCongelada())
                                        <a class="btn ghost" href="{{ route('admin.keyword-campaigns.edit', $campanha) }}">Editar</a>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Nenhuma campanha cadastrada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $campaigns->links() }}
    </section>
</x-layouts.app>
