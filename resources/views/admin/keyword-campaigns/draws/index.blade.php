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
            {{ $cuponsEmEstoque === 1 ? 'disponível' : 'disponíveis' }} de {{ $cuponsTotal }}.
        </p>
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
                        <p class="muted">O primeiro sorteado é o ganhador; os seguintes formam a fila de suplentes.</p>
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
                                    <td>{{ $posicao + 1 }}{{ $posicao === 0 ? 'º (ganhador)' : 'º (suplente)' }}</td>
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

                <div class="actions" style="margin-top:12px;">
                    <form method="post" action="{{ route('admin.keyword-campaigns.draws.verify', [$campaign, $sorteio]) }}">
                        @csrf
                        <button class="btn ghost" type="submit"><x-icon name="refresh" size="16" />Refazer a conta</button>
                    </form>
                    @can('keyword_coupons.manage')
                        <form method="post" action="{{ route('admin.keyword-campaigns.draws.deliver', $campaign) }}">
                            @csrf
                            <button class="btn" type="submit"><x-icon name="send" size="16" />Entregar os cupons</button>
                        </form>
                    @endcan
                </div>
            </div>
        @empty
            <p class="muted">Nenhum sorteio executado.</p>
        @endforelse
    </section>
</x-layouts.app>
