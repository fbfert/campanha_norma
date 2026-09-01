<x-layouts.app title="Sorteio" breadcrumbs="Inicio / Campanhas por palavra-chave / Sorteio">
    <section class="card">
        <h2>{{ $campaign->name }} — sorteio</h2>
        @if($campaign->estaCongelada())
            <p>
                Lista congelada em {{ $campaign->frozen_at->format('d/m/Y H:i') }} com
                {{ $campaign->frozen_list_count }}
                {{ $campaign->frozen_list_count === 1 ? 'participante' : 'participantes' }}.
            </p>
            <p class="muted">Hash da lista: <code>{{ $campaign->frozen_list_hash }}</code></p>
        @else
            <div class="alert warning">
                A lista ainda não foi congelada. Sem congelamento a lista muda entre o sorteio e o anúncio, e ninguém
                de fora consegue conferir que foi sorteado o que foi publicado.
                <a href="{{ route('admin.keyword-campaigns.eligibility.index', $campaign) }}">Ir para a conferência</a>.
            </div>
        @endif
        <p>
            Cupons: <strong>{{ $cuponsEmEstoque }}</strong>
            {{ $cuponsEmEstoque === 1 ? 'disponível' : 'disponíveis' }},
            <strong>{{ $cuponsAEntregar }}</strong>
            {{ $cuponsAEntregar === 1 ? 'atribuído esperando entrega' : 'atribuídos esperando entrega' }},
            <strong>{{ $cuponsEntregues }}</strong>
            {{ $cuponsEntregues === 1 ? 'entregue' : 'entregues' }} — de {{ $cuponsTotal }} no total.
        </p>
        @if($cuponsTotal > 0)
            <p class="muted"><a href="#cupons">Ver os cupons um a um</a></p>
        @endif
    </section>

    @can('keyword_coupons.manage')
        <section class="card" style="margin-top:16px;">
            <h2>Importar cupons</h2>
            <p class="muted">
                CSV ou XLSX gerado no portal, com a coluna <code>codigo</code>, <code>code</code>, {{-- ortografia:ignorar — cabeçalhos de CSV lidos pelo importador, que não levam acento --}}
                <code>cupom</code> ou <code>coupon</code>; um arquivo de uma coluna só é lido como lista de códigos.
                Reimportar o mesmo arquivo não duplica nada.
            </p>
            <p class="muted">
                Cupom é valor: o código não aparece em log, em exportação nem no histórico da conversa. O que fica
                gravado no histórico é uma referência ao cupom.
            </p>
            <form method="post" action="{{ route('admin.keyword-campaigns.draws.coupons', $campaign) }}" enctype="multipart/form-data">
                @csrf
                <label for="arquivo">Arquivo de cupons</label>
                <input id="arquivo" name="arquivo" type="file" accept=".csv,.txt,.xlsx" required>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit"><x-icon name="upload" size="16" />Importar cupons</button>
                </div>
            </form>
        </section>
    @endcan

    @can('keyword_coupons.manage')
        <section class="card" style="margin-top:16px;">
            <h2>Cadastrar cupons à mão</h2>
            <p class="muted">
                Um código por linha, um cupom para cada ganhador. Vírgula e ponto e vírgula também separam, para o
                caso de colar tudo de uma vez. Serve para o prêmio que veio em um e-mail e não em planilha: montar
                um arquivo só para isso é criar um arquivo com cupom dentro para ter de apagar depois.
            </p>
            <p class="muted">
                Cadastrar o mesmo código duas vezes não duplica nada, e vale a mesma regra da importação: o código
                não aparece em log, em exportação nem no histórico da conversa.
            </p>
            <form method="post" action="{{ route('admin.keyword-campaigns.draws.coupons.manual', $campaign) }}">
                @csrf
                <label for="codigos">Códigos</label>
                <textarea id="codigos" name="codigos" rows="6" maxlength="20000" required
                          placeholder="CURSO-AAA&#10;CURSO-BBB&#10;CURSO-CCC">{{ old('codigos') }}</textarea>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit"><x-icon name="plus" size="16" />Cadastrar cupons</button>
                </div>
            </form>
        </section>
    @endcan

    @can('keyword_draws.execute')
        <section class="card" style="margin-top:16px;">
            <h2>Executar o sorteio</h2>
            <p class="muted">
                Só sobre lista congelada, e só quando houver cupom para cada ganhador. Sortear primeiro e descobrir
                depois que falta prêmio obriga a escolher entre não entregar a um ganhador anunciado e refazer o
                sorteio — e as duas saídas destroem a auditabilidade.
            </p>
            <p class="muted">
                A semente em branco é gerada na hora. Ela fica registrada em claro de propósito: semente em segredo
                não serve de auditoria nenhuma.
            </p>
            <form method="post" action="{{ route('admin.keyword-campaigns.draws.store', $campaign) }}">
                @csrf
                <div class="grid grid-2">
                    <div>
                        <label for="quantity">Quantos ganhadores</label>
                        <input id="quantity" name="quantity" type="number" min="1" max="1000" value="{{ old('quantity', 1) }}" required>
                        <p class="muted">Todos os sorteados são ganhadores, e cada um recebe um cupom — por isso o sorteio recusa executar sem cupom para todos. A ordem fica registrada porque faz parte da conta que pode ser refeita, e não porque classifique alguém.</p>
                    </div>
                    <div>
                        <label for="seed">Semente</label>
                        <input id="seed" name="seed" value="{{ old('seed') }}" maxlength="128">
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <label for="note">Observação</label>
                    <input id="note" name="note" value="{{ old('note') }}" maxlength="500">
                </div>
                <div style="margin-top:12px;">
                    <label for="confirmacao">
                        <input id="confirmacao" name="confirmacao" type="checkbox" value="1" required>
                        Confirmo que quero executar o sorteio agora.
                    </label>
                </div>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit" @disabled(! $campaign->estaCongelada())>Sortear</button>
                </div>
            </form>
        </section>
    @endcan

    @can('keyword_coupons.manage')
        <section class="card" style="margin-top:16px;">
            <h2>Entregar os cupons</h2>
            <p class="muted">
                A mensagem que o ganhador vai ler. <code>{codigo}</code> é obrigatório e vira o cupom no momento do {{-- ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento --}}
                envio; <code>{nome}</code> é opcional e vira o nome conferido na tela de elegibilidade.
            </p>
            <p class="muted">
                O que fica gravado aqui é o molde, nunca o código. Escreva o que a pessoa precisa fazer com o cupom
                depois de recebê-lo: "seu código de acesso é {codigo}" não diz onde usá-lo, e quem ganhou vai {{-- ortografia:ignorar - {codigo} é nome de placeholder, comparado pelo código e por isso sem acento --}}
                perguntar isso na mesma conversa.
            </p>
            <form method="post" action="{{ route('admin.keyword-campaigns.draws.deliver', $campaign) }}">
                @csrf
                <label for="mensagem">Mensagem ao ganhador</label>
                <textarea id="mensagem" name="mensagem" rows="4" maxlength="4000" required>{{ old('mensagem', $mensagemDoCupom) }}</textarea>
                <p class="muted">
                    @if($cuponsAEntregar === 0)
                        Nenhum cupom esperando entrega. A mensagem fica salva para o próximo sorteio.
                    @else
                        <strong>{{ $cuponsAEntregar }}</strong>
                        {{ $cuponsAEntregar === 1 ? 'cupom espera entrega' : 'cupons esperam entrega' }}.
                        O envio passa pelo mesmo teto das confirmações.
                    @endif
                </p>
                <div class="actions" style="margin-top:12px;">
                    <button class="btn" type="submit"><x-icon name="send" size="16" />Entregar os cupons</button>
                </div>
            </form>
        </section>
    @endcan

    <section class="card" style="margin-top:16px;" id="cupons">
        <h2>Cupons</h2>
        @if($cuponsTotal === 0)
            <p class="muted">Nenhum cupom cadastrado ainda.</p>
        @else
            <p class="muted">
                Os usados aparecem primeiro: quem abre esta tela depois do sorteio quer saber para quem o prêmio foi.
                @unless($podeVerCodigos)
                    O código só aparece para quem administra cupons; aqui cada cupom é identificado pela referência.
                @endunless
            </p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Situação</th>
                            @if($podeVerCodigos)
                                <th>Código</th>
                            @endif
                            <th>Referência</th>
                            <th>Ganhador</th>
                            <th>Telefone</th>
                            <th>Atribuído em</th>
                            <th>Entregue em</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($cupons as $cupom)
                            <tr>
                                <td>
                                    @php($situacao = $cupom->status)
                                    <span class="badge @if($cupom->delivered_at) coupon-delivered @elseif($cupom->keyword_campaign_participation_id) coupon-assigned @endif">
                                        {{ $situacao->label() }}
                                    </span>
                                </td>
                                @if($podeVerCodigos)
                                    <td><code>{{ $codigos[$cupom->id] ?? '—' }}</code></td>
                                @endif
                                <td><code>{{ $cupom->reference }}</code></td>
                                <td>{{ $cupom->participation?->displayName() ?? '—' }}</td>
                                <td>{{ $cupom->participation?->contact?->phone_normalized ?? '—' }}</td>
                                <td>{{ $cupom->assigned_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>{{ $cupom->delivered_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $cupons->links() }}
        @endif
    </section>

    <section class="card" style="margin-top:16px;">
        <h2>Sorteios executados</h2>
        @forelse($draws as $sorteio)
            <div class="card" style="margin-top:12px;">
                <p>
                    <strong>{{ $sorteio->quantity }}</strong>
                    {{ $sorteio->quantity === 1 ? 'ganhador' : 'ganhadores' }},
                    em {{ $sorteio->executed_at?->format('d/m/Y H:i') }}
                    por {{ $sorteio->executor?->name ?? 'usuário removido' }}.
                </p>
                <p class="muted">Semente: <code>{{ $sorteio->seed }}</code></p>
                <p class="muted">Hash da lista: <code>{{ $sorteio->list_hash }}</code></p>
                @if($sorteio->note)
                    <p class="muted">{{ $sorteio->note }}</p>
                @endif

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Ordem</th>
                                <th>Nome</th>
                                <th>Telefone</th>
                                @if($podeVerCodigos)
                                    <th>Cupom</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sorteio->participacoesSorteadas() as $posicao => $participacao)
                                <tr>
                                    {{-- A ordem é a do sorteio, e serve para refazer a conta. Ela não
                                         classifica ninguém: o cupom vai para todos os sorteados, e o
                                         rótulo antigo, que rebaixava do segundo em diante, era a tela
                                         contradizendo o que o sistema já fazia. --}}
                                    <td>{{ $posicao + 1 }}º ganhador</td>
                                    <td>{{ $participacao->displayName() ?? '—' }}</td>
                                    <td>{{ $participacao->contact?->phone_normalized ?? '—' }}</td>
                                    @if($podeVerCodigos)
                                        <td>
                                            @php($cupom = $campaign->coupons->firstWhere('keyword_campaign_participation_id', $participacao->id))
                                            {{ $cupom ? app(\App\Services\KeywordCampaigns\CouponService::class)->revelar($cupom) : '—' }}
                                            @if($cupom?->delivered_at)
                                                <br><span class="muted">entregue em {{ $cupom->delivered_at->format('d/m/Y H:i') }}</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Refazer a conta é por sorteio; a entrega não é, e por isso
                     saiu daqui para um card próprio: o botão entregava os cupons
                     pendentes da campanha inteira, repetido embaixo de cada
                     sorteio como se fosse daquele. --}}
                <div class="actions" style="margin-top:12px;">
                    <form method="post" action="{{ route('admin.keyword-campaigns.draws.verify', [$campaign, $sorteio]) }}">
                        @csrf
                        <button class="btn ghost" type="submit"><x-icon name="refresh" size="16" />Refazer a conta</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="muted">Nenhum sorteio executado.</p>
        @endforelse
    </section>
</x-layouts.app>
