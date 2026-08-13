<x-layouts.app title="Perfis de atendimento" breadcrumbs="Inicio / Atendimento de entrada / Perfis">
    <section class="card">
        <h2>Atendimento de entrada</h2>
        <p class="muted">
            Cada perfil é o equivalente de um lote para quem escreve primeiro: diz qual fluxo abrir, o que responder,
            em que horário e com que teto. A diferença é a seleção — no lote nós escolhemos os contatos, aqui quem escolhe é quem escreve.
        </p>
        <p>
            Situação: <strong>{{ $enabled ? 'ligado' : 'desligado' }}</strong>
            — {{ $startedToday }} {{ $startedToday === 1 ? 'conversa aberta automaticamente hoje' : 'conversas abertas automaticamente hoje' }}.
        </p>
        <div class="actions">
            @can('inbound_attendance.manage_profiles')
                <form method="post" action="{{ route('admin.inbound-attendance.toggle') }}">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
                    <button class="btn {{ $enabled ? 'secondary' : '' }}" type="submit">
                        {{ $enabled ? 'Desligar tudo' : 'Ligar atendimento' }}
                    </button>
                </form>
                <a class="btn" href="{{ route('admin.inbound-attendance.profiles.create') }}"><x-icon name="plus" size="16" />Novo perfil</a>
            @endcan
            <a class="btn ghost" href="{{ route('admin.inbound-attendance.index') }}">Ver a fila</a>
        </div>
    </section>

    @if($fallbackFaltando)
        <div class="alert warning" style="margin-top:16px;">
            Nenhum perfil ativo está marcado para atender o que sobrou. Quem escrever algo que nenhuma expressão prevê fica sem resposta.
        </div>
    @endif

    @can('inbound_attendance.manage_profiles')
        <section class="card" style="margin-top:16px;">
            <h2>Expressões de exclusão</h2>
            <p class="muted">
                Nem toda mensagem recebida é alguém falando com a gente: operadora avisa saldo, banco manda código,
                robô de recarga oferece serviço. Mensagem que casar com uma destas frases não abre atendimento e não
                ocupa a fila — mas fica registrada e aparece na fila, em "Ignoradas hoje", para uma regra larga demais
                não engolir uma pessoa em silêncio.
            </p>
            <p class="muted">
                Frases inteiras, uma por linha. <code>recarga</code> sozinha pegaria quem escreve sobre o preço da
                recarga, e é justamente essa pessoa que se quer atender.
            </p>
            <form method="post" action="{{ route('admin.inbound-attendance.exclusions') }}">
                @csrf
                @method('put')
                <label for="exclusion_expressions">Frases que não são atendidas</label>
                <textarea id="exclusion_expressions" name="exclusion_expressions" rows="8">{{ old('exclusion_expressions', $exclusions) }}</textarea>

                <label for="internal_phones" style="margin-top:16px;">Telefones da equipe</label>
                <textarea id="internal_phones" name="internal_phones" rows="4">{{ old('internal_phones', $internalPhones) }}</textarea>
                <p class="muted">
                    Um por linha, só os números. Estes nunca recebem resposta automática — nem do atendimento de entrada,
                    nem da rede de segurança. Resposta manual continua funcionando, que é o que se quer numa conversa de trabalho.
                </p>
                <p class="muted">
                    Existe porque nenhuma regra de conteúdo distingue a equipe de um eleitor: a candidata escreveu "Oiii"
                    e recebeu de volta "Recebemos sua mensagem, nossa equipe vai ler com atenção".
                </p>

                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit">Salvar exclusões</button>
                </div>
            </form>
        </section>
    @endcan

    <section class="card" style="margin-top:16px;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Situação</th>
                        <th>Atende</th>
                        <th>Fluxo</th>
                        <th>Abertura</th>
                        <th>Janela</th>
                        <th>Teto diário</th>
                        <th>Conversas</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profiles as $perfil)
                        <tr>
                            <td>
                                {{ $perfil->name }}
                                <br><span class="muted">{{ $perfil->description }}</span>
                            </td>
                            <td>
                                {{ $perfil->status->label() }}
                                @if($perfil->needsHumanApproval())
                                    <br><span class="muted">em homologação: {{ $perfil->approved_starts_count }}/{{ $perfil->homologation_threshold }}</span>
                                @endif
                            </td>
                            <td>
                                @if($perfil->is_fallback)
                                    o que sobrou
                                @else
                                    {{ count($perfil->matchExpressionList()) }} {{ count($perfil->matchExpressionList()) === 1 ? 'expressão' : 'expressões' }}
                                @endif
                            </td>
                            <td>{{ $perfil->conversationFlow?->name ?? '—' }}</td>
                            <td>{{ $perfil->opening_mode->label() }}</td>
                            <td>{{ $perfil->window_start ? $perfil->window_start.' às '.$perfil->window_end : 'janela geral' }}</td>
                            <td>{{ $perfil->daily_start_limit ?: 'sem teto' }}</td>
                            <td>{{ $perfil->started_count }}</td>
                            <td class="actions">
                                @can('inbound_attendance.manage_profiles')
                                    <a class="btn ghost" href="{{ route('admin.inbound-attendance.profiles.edit', $perfil) }}">Editar</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9">Nenhum perfil cadastrado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $profiles->links() }}
    </section>
</x-layouts.app>
