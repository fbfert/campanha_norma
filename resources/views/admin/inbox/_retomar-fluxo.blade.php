{{--
    Botão de retomar a pesquisa automática de uma conversa.

    A automação repassa a conversa para um humano quando não sabe seguir
    sozinha: pausa o fluxo e marca `needs_human_review`. Responder à pessoa não
    desfaz isso — a pesquisa dela fica parada até alguém retomar.

    A ação já existia, mas só na tela de Pesquisa conversacional. Quem estava
    atendendo tinha de sair da conversa, achar o estado correspondente em outra
    tela e retomar de lá, o que na prática significava não retomar. O botão vive
    aqui porque é aqui que a pessoa percebe que quer retomar.

    Some sozinho quando não há o que retomar: sem fluxo, ou fluxo rodando, não
    existe botão. Botão que não faz nada ensina a ignorar botão.
--}}
@php($estadoDoFluxo = $conversation->flowState)

@if($estadoDoFluxo?->is_paused)
    @can('conversation_automation.control')
        <div class="actions">
            <form method="post" action="{{ route('admin.conversation-automation.resume', $estadoDoFluxo) }}"
                onsubmit="return confirm('Retomar a pesquisa automática desta conversa? Ela volta a responder sozinha na próxima mensagem da pessoa.')">
                @csrf
                <button class="btn secondary" type="submit">
                    <x-icon name="play" size="16" />Retomar fluxo
                </button>
            </form>
        </div>
    @endcan
@endif
