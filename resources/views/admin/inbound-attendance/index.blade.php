<x-layouts.app title="Mensagens aguardando resposta" breadcrumbs="Inicio / Atendimento de entrada">
    <section class="card">
        <h2>Aguardando resposta</h2>
        <p class="muted">
            Conversas em que a última palavra é da pessoa e a automação não resolveu — porque uma trava recusou,
            ou porque o tempo de carência passou e nada aconteceu. O motivo aparece em cada linha.
        </p>
        @can('inbound_attendance.view')
            <p class="muted"><a href="{{ route('admin.inbound-attendance.profiles.index') }}">Perfis de atendimento</a></p>
        @endcan
    </section>

    @if($pending->total() === 0)
        <section class="card" style="margin-top:16px;">
            <p><x-icon name="check" size="16" /> Nenhuma conversa esperando resposta.</p>
        </section>
    @else
        {{-- Alpine, e não um script solto na view: o sistema já carrega Alpine
             pelo bundle, e marcar caixas não merece um arquivo de JavaScript
             próprio nem uma tag de script que nenhum teste alcança. --}}
        <form method="post" action="{{ route('admin.inbound-attendance.start') }}" x-data="{ todas: false }">
            @csrf
            <section class="card" style="margin-top:16px;">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:36px;">
                                    {{-- Selecionar todas marca só o que está nesta página: marcar
                                         o que não se vê seria iniciar conversa às cegas. --}}
                                    <input type="checkbox" id="selecionar-todas" x-model="todas"
                                           @change="$el.closest('form').querySelectorAll('.selecao-conversa').forEach(c => c.checked = todas)"
                                           aria-label="Selecionar todas desta página">
                                </th>
                                <th>Contato</th>
                                <th>Última mensagem</th>
                                <th>Recebida em</th>
                                <th>Por que está parada</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pending as $conversa)
                                @php
                                    $mensagem = $lastMessages[$conversa->id] ?? null;
                                    $motivo = $reasons[$conversa->id] ?? null;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="conversation_ids[]" value="{{ $conversa->id }}"
                                               class="selecao-conversa" aria-label="Selecionar conversa {{ $conversa->id }}">
                                    </td>
                                    <td>
                                        {{ $conversa->contact?->name ?? 'Sem contato identificado' }}
                                        <br><span class="muted">{{ $conversa->contact?->phone_normalized ?? $conversa->whatsappPhoneDigits() }}</span>
                                    </td>
                                    <td>{{ Str::limit($mensagem?->body, 120) ?: '—' }}</td>
                                    <td>{{ $conversa->last_incoming_message_at?->format($dateTimeFormat) }}</td>
                                    <td>
                                        {{ $motivo?->reasonLabel() ?? 'Ainda não avaliada' }}
                                        @if($motivo?->profile)
                                            <br><span class="muted">{{ $motivo->profile->name }}</span>
                                        @endif
                                    </td>
                                    <td class="actions">
                                        <a class="btn ghost" href="{{ route('admin.conversations.show', $conversa) }}">Abrir</a>
                                        @can('inbound_attendance.start')
                                            {{-- Fora do formulário de iniciar: dois formulários aninhados não
                                                 existem em HTML, e o navegador desmonta o de dentro sem avisar. --}}
                                            <button class="btn ghost" type="submit" form="ignorar-{{ $conversa->id }}">Ignorar</button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $pending->links() }}
            </section>

            @can('inbound_attendance.start')
                <section class="card" style="margin-top:16px;">
                    <h2>Iniciar conversa automática</h2>
                    <p class="muted">
                        Sem perfil escolhido, cada conversa vai para o perfil que o conteúdo da mensagem indicar,
                        e para o perfil que atende o que sobrou quando nenhuma expressão casar.
                    </p>
                    <div class="grid grid-2">
                        <div>
                            <label for="inbound_attendance_profile_id">Perfil de atendimento</label>
                            <select id="inbound_attendance_profile_id" name="inbound_attendance_profile_id">
                                <option value="">Decidir pelo conteúdo da mensagem</option>
                                @foreach($profiles as $perfil)
                                    <option value="{{ $perfil->id }}">{{ $perfil->name }}@if($perfil->needsHumanApproval()) (em homologação)@endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="actions" style="align-items:end;">
                            <button class="btn" type="submit"><x-icon name="play" size="16" />Iniciar selecionadas</button>
                        </div>
                    </div>
                    @if($profiles->isEmpty())
                        <p class="muted" style="margin-top:8px;">
                            Nenhum perfil ativo. Crie um em <a href="{{ route('admin.inbound-attendance.profiles.index') }}">Perfis de atendimento</a>.
                        </p>
                    @endif
                </section>
            @endcan
        </form>

        {{--
            Um formulário de ignorar por conversa, fora do formulário de iniciar.

            HTML não aninha formulário: o navegador desmonta o de dentro sem
            avisar, e o botão passaria a submeter o de fora — que inicia
            conversa. O atributo `form` no botão liga os dois à distância, que é
            exatamente para isto que ele existe.

            A confirmação é a mesma que o resto do sistema usa: retirar da fila
            não manda nada, mas some com a linha da tela, e sumiço sem pergunta
            é como se perde uma pendência por um clique torto.
        --}}
        @can('inbound_attendance.start')
            @foreach($pending as $conversa)
                <form id="ignorar-{{ $conversa->id }}" method="post"
                      action="{{ route('admin.inbound-attendance.ignore', $conversa) }}"
                      onsubmit="return confirm('Tirar esta conversa da fila de pendentes? Nenhuma mensagem será enviada. Se a pessoa escrever de novo, ela volta para a fila.')">
                    @csrf
                </form>
            @endforeach
        @endcan
    @endif

    @if($skippedToday->isNotEmpty())
        <section class="card" style="margin-top:16px;">
            <h2>Ignoradas hoje</h2>
            <p class="muted">
                O que saiu da fila sem receber resposta: mensagem que casou com uma expressão de exclusão — operadora,
                banco, robô — e conversa que alguém ignorou à mão. Estão aqui porque uma regra larga demais, ou um
                clique na linha errada, engoliria uma pessoa de verdade, e isso precisa ser visível.
                @can('inbound_attendance.manage_profiles')
                    As expressões ficam em <a href="{{ route('admin.inbound-attendance.profiles.index') }}">Perfis de atendimento</a>.
                @endcan
            </p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Contato</th><th>Mensagem</th><th>Por quê</th><th>Ações</th></tr></thead>
                    <tbody>
                        @foreach($skippedToday as $ignorada)
                            <tr>
                                <td>{{ $ignorada->conversation?->contact?->name ?? 'Sem contato identificado' }}</td>
                                <td>{{ Str::limit($ignorada->message?->body, 90) ?: '—' }}</td>
                                <td>
                                    {{ $ignorada->reasonLabel() }}
                                    @if($ignorada->starter)
                                        <br><span class="muted">{{ $ignorada->starter->name }}</span>
                                    @elseif($ignorada->metadata['expressao'] ?? null)
                                        <br><span class="muted"><code>{{ $ignorada->metadata['expressao'] }}</code></span>
                                    @endif
                                </td>
                                <td class="actions">
                                    @if($ignorada->conversation)
                                        <a class="btn ghost" href="{{ route('admin.conversations.show', $ignorada->conversation) }}">Abrir</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card" style="margin-top:16px;">
        <h2>Atendidas hoje</h2>
        <p class="muted">Conversas que o atendimento abriu hoje. Não pedem nada — estão aqui para você ver o que foi dito em seu nome.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Contato</th><th>Última mensagem em</th><th>Ações</th></tr></thead>
                <tbody>
                    @forelse($startedToday as $conversa)
                        <tr>
                            <td>{{ $conversa->contact?->name ?? 'Sem contato identificado' }}</td>
                            <td>{{ $conversa->last_message_at?->format($dateTimeFormat) }}</td>
                            <td class="actions"><a class="btn ghost" href="{{ route('admin.conversations.show', $conversa) }}">Abrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Nenhuma conversa aberta hoje.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
