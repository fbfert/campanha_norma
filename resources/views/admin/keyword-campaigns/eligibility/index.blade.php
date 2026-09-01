<x-layouts.app title="Conferência de elegibilidade" breadcrumbs="Inicio / Campanhas por palavra-chave / Conferência">
    <section class="card">
        <h2>{{ $campaign->name }} — conferência</h2>
        <p class="muted">
            A campanha é entre alunos, mas a entrada não verifica nada: qualquer pessoa se inscreve. A importação
            abaixo <strong>marca</strong> quem é aluno, sem recusar ninguém e sem criar contato. O que não casar espera
            conferência humana.
        </p>
        <p>
            <strong>{{ $totalPendente }}</strong>
            {{ $totalPendente === 1 ? 'inscrição esperando conferência' : 'inscrições esperando conferência' }}
            — <strong>{{ $totalDaLista }}</strong>
            {{ $totalDaLista === 1 ? 'já elegível ao sorteio' : 'já elegíveis ao sorteio' }}.
        </p>
    </section>

    @can('keyword_participations.invalidate')
        <section class="card" style="margin-top:16px;">
            <h2>Importar a lista de alunos</h2>
            <p class="muted">
                CSV ou XLSX exportado do portal. A coluna de telefone é reconhecida pelo cabeçalho
                <code>telefone</code>, <code>phone</code>, <code>celular</code> ou <code>whatsapp</code>; um arquivo de
                uma coluna só é lido como lista de telefones. O nono dígito não atrapalha: as duas formas são testadas.
            </p>
            <p class="muted">
                Rodar duas vezes não muda nada. Quem um humano já marcou como não aluno continua não aluno — a decisão
                da pessoa vence a do arquivo.
            </p>
            <form method="post" action="{{ route('admin.keyword-campaigns.eligibility.import', $campaign) }}" enctype="multipart/form-data">
                @csrf
                <label for="arquivo">Arquivo</label>
                <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx" required>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit"><x-icon name="upload" size="16" />Importar e marcar</button>
                </div>
            </form>
        </section>
    @endcan

    @can('keyword_campaigns.manage')
        <section class="card" style="margin-top:16px;">
            <h2>Congelar a lista</h2>
            <p class="muted">
                O congelamento fecha a lista que vai ao sorteio e para de aceitar inscrições. Ele exige que a fila de
                conferência esteja vazia: uma lista congelada com inelegível dentro obriga a resortear, e sorteio
                refeito porque o ganhador não servia é indistinguível, de fora, de sorteio refeito porque o ganhador
                não agradou.
            </p>
            @if($campaign->estaCongelada())
                <p>
                    Congelada em {{ $campaign->frozen_at->format('d/m/Y H:i') }} com
                    {{ $campaign->frozen_list_count }}
                    {{ $campaign->frozen_list_count === 1 ? 'participante' : 'participantes' }}.
                </p>
                <p class="muted">Hash da lista: <code>{{ $campaign->frozen_list_hash }}</code></p>
                <form method="post" action="{{ route('admin.keyword-campaigns.eligibility.unfreeze', $campaign) }}">
                    @csrf
                    @method('put')
                    <label for="motivo">Motivo para descongelar</label>
                    <input id="motivo" name="motivo" required minlength="5" maxlength="500">
                    <p class="muted">Descongelar permite refazer o sorteio. Um sorteio já executado continua registrado como está.</p>
                    <div class="actions" style="margin-top:12px;">
                        <button class="btn secondary" type="submit">Descongelar</button>
                    </div>
                </form>
            @else
                <form method="post" action="{{ route('admin.keyword-campaigns.eligibility.freeze', $campaign) }}">
                    @csrf
                    <button class="btn" type="submit" @disabled($totalPendente > 0)>Congelar lista</button>
                    @if($totalPendente > 0)
                        <p class="muted">Faltam {{ $totalPendente }} para conferir.</p>
                    @endif
                </form>
            @endif
        </section>
    @endcan

    <section class="card" style="margin-top:16px;">
        <h2>Fila de conferência</h2>
        @can('keyword_participations.invalidate')
            {{-- Alpine, e não um script solto na view: o sistema já carrega Alpine
                 pelo bundle, e marcar caixas não merece um arquivo de JavaScript
                 próprio nem uma tag de script que nenhum teste alcança. --}}
            <form method="post" action="{{ route('admin.keyword-campaigns.eligibility.review', $campaign) }}"
                  x-data="{ todas: false }">
                @csrf
                @method('put')
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Marcar</th>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Palavra</th>
                                <th>Inscrito em</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pendentes as $participacao)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="participations[]" value="{{ $participacao->id }}"
                                               class="selecao-pendente"
                                               aria-label="Selecionar {{ $participacao->displayName() ?? 'inscrição '.$participacao->id }}">
                                    </td>
                                    <td>{{ $participacao->displayName() ?? '—' }}</td>
                                    <td>{{ $participacao->contact?->phone_normalized ?? '—' }}</td>
                                    <td>{{ $participacao->matched_keyword }}</td>
                                    <td>{{ $participacao->created_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">Nenhuma inscrição esperando conferência.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($pendentes->isNotEmpty())
                    <div class="actions" style="margin-top:12px;">
                        {{-- O botão marca as caixas que estão na tela. O texto de partida
                             vem do servidor, para a tela dizer o que o botão faz mesmo
                             antes de o Alpine subir. --}}
                        <button class="btn ghost" type="button"
                                x-on:click="todas = ! todas; $el.closest('form').querySelectorAll('.selecao-pendente').forEach(caixa => caixa.checked = todas)">
                            <x-icon name="check" size="16" />
                            <span x-text="todas ? 'Desmarcar todos' : 'Marcar todos'">Marcar todos</span>
                        </button>
                        <label for="eligibility">Marcar as selecionadas como</label>
                        <select id="eligibility" name="eligibility">
                            <option value="aluno_confirmado">Aluno confirmado</option>
                            <option value="nao_aluno">Não é aluno</option>
                        </select>
                        <button class="btn" type="submit">Conferir selecionadas</button>
                    </div>

                    {{-- Marcar as caixas alcança só esta página; a fila inteira é uma
                         escolha à parte, e não um efeito colateral de um botão. Ela diz
                         o número que vai conferir porque conferir a fila inteira é
                         conferir também quem ninguém leu, e quem decide isso precisa
                         ver o tamanho da decisão antes de tomá-la. --}}
                    @if($pendentes->hasPages())
                        <p style="margin-top:12px;">
                            <label>
                                <input type="checkbox" name="fila_inteira" value="1">
                                Aplicar às {{ $totalPendente }} da fila inteira, e não só às desta página
                            </label>
                        </p>
                    @endif
                @endif
            </form>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Nome</th><th>Telefone</th><th>Inscrito em</th></tr></thead>
                    <tbody>
                        @forelse($pendentes as $participacao)
                            <tr>
                                <td>{{ $participacao->displayName() ?? '—' }}</td>
                                <td>{{ $participacao->contact?->phone_normalized ?? '—' }}</td>
                                <td>{{ $participacao->created_at?->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">Nenhuma inscrição esperando conferência.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endcan
        {{ $pendentes->links() }}
    </section>
</x-layouts.app>
