<x-layouts.app title="Limpeza de participações" breadcrumbs="Inicio / Limpeza / Participações">
    @php
        $porAlvo = collect($itens)->groupBy(fn ($item) => $item['target']->value);
        $temSorteio = collect($itens)->contains(fn ($item) => $item['envolve_sorteio']);
    @endphp

    <section class="card">
        <h2>{{ $contact->name }}</h2>
        <p class="muted">
            {{ $contact->phone ?? $contact->phone_normalized }}
            @if($contact->do_not_contact) · marcado como não contatar @endif
        </p>
        <p class="muted">
            Marque o que deve sair. O que for removido some na hora de todo o sistema e fica
            <strong>{{ $diasNaLixeira }}</strong> {{ $diasNaLixeira === 1 ? 'dia' : 'dias' }} na lixeira, de onde volta
            inteiro. Depois disso, não volta mais.
        </p>
        <p class="muted">
            Limpar não impede a pessoa de participar de novo: se ela responder a uma palavra-chave ou mandar mensagem
            outra vez, entra normalmente. A Limpeza trata do passado.
        </p>
    </section>

    @if(count($itens) === 0)
        <section class="card">
            <div class="empty-state">
                <x-icon name="check" size="32" />
                <p>Este contato não participou de nada que a Limpeza saiba remover.</p>
                <p class="muted">Sem inscrição em campanha, conversa, lote de envio, etiqueta ou histórico registrado.</p>
            </div>
        </section>
    @else
        <form method="post" action="{{ route('admin.cleanup.store', $contact) }}">
            @csrf

            @foreach($porAlvo as $valorAlvo => $itensDoAlvo)
                @php $alvo = \App\Enums\CleanupTarget::from($valorAlvo); @endphp
                <section class="card">
                    <h2>{{ $alvo->label() }}</h2>
                    <p class="muted">{{ $alvo->description() }}</p>

                    <ul class="stack-list">
                        @foreach($itensDoAlvo as $item)
                            <li>
                                <label class="checkbox">
                                    <input type="checkbox" name="itens[]" value="{{ $item['chave'] }}"
                                        @checked(in_array($item['chave'], old('itens', []), true))>
                                    <strong>{{ $item['nome'] }}</strong>
                                </label>
                                <p class="muted">
                                    {{ $item['detalhe'] }}
                                    · {{ $item['quantidade'] }} {{ $item['quantidade'] === 1 ? 'registro' : 'registros' }}
                                </p>
                                @foreach($item['avisos'] as $aviso)
                                    <div class="alert warning">{{ $aviso }}</div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            <section class="card">
                <h2>Confirmação</h2>
                <p class="muted">
                    O telefone digitado e o motivo escrito vão para a auditoria. O telefone existe para o caso de a busca
                    ter trazido homônimos; o motivo, para explicar a remoção a quem ler isso muito depois.
                </p>

                <p>
                    <label for="telefone_confirmado">Digite o telefone deste contato</label>
                    <input id="telefone_confirmado" name="telefone_confirmado" value="{{ old('telefone_confirmado') }}" required>
                </p>

                <p>
                    <label for="motivo">Por que esta limpeza está sendo feita</label>
                    <textarea id="motivo" name="motivo" rows="3" minlength="10" maxlength="500" required>{{ old('motivo') }}</textarea>
                </p>

                @if($temSorteio)
                    <div class="alert warning">
                        <strong>Há inscrição já sorteada entre as participações desta pessoa.</strong>
                        Remover uma delas reescreve um resultado que já foi apurado e possivelmente divulgado. Se for
                        mesmo o caso, confirme abaixo.
                    </div>
                    <label class="checkbox">
                        <input type="checkbox" name="confirmo_sorteio" value="1">
                        Confirmo remover participação que já foi sorteada
                    </label>
                @endif

                <div class="actions">
                    <button class="btn" type="submit" name="modo" value="selecionados">
                        <x-icon name="trash" size="16" />Limpar o que está marcado
                    </button>
                    <button class="btn danger" type="submit" name="modo" value="tudo">
                        Limpar tudo desta pessoa ({{ count($itens) }} {{ count($itens) === 1 ? 'item' : 'itens' }})
                    </button>
                </div>
            </section>
        </form>
    @endif

    @if($limpezas->isNotEmpty())
        <section class="card">
            <h2>Limpezas anteriores deste contato</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Quando</th><th>Itens</th><th>Motivo</th><th>Situação</th></tr>
                    </thead>
                    <tbody>
                        @foreach($limpezas as $operacao)
                            <tr>
                                <td>{{ $operacao->executed_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $operacao->items_count }}</td>
                                <td>{{ $operacao->reason }}</td>
                                <td>
                                    @if($operacao->restored_at)
                                        restaurada em {{ $operacao->restored_at->format('d/m/Y H:i') }}
                                    @elseif($operacao->purged_at)
                                        apagada em definitivo
                                    @else
                                        na lixeira até {{ $operacao->expires_at->format('d/m/Y H:i') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>
