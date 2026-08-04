<x-layouts.app title="Mapa mental" breadcrumbs="Inicio / Manual / Mapa mental">
    {{--
        O mapa e uma lista aninhada de verdade, desenhada com CSS. Não e imagem
        e não e biblioteca de diagrama.

        Isso resolve três coisas de uma vez: quem usa leitor de tela ouve uma
        lista com hierarquia em vez de um bloco sem significado, o mapa imprime
        junto com o resto da página, e ninguém precisa de rede para desenha-lo.
        Cada galho leva para a seção correspondente do manual.
    --}}
    <section class="card">
        <h2>Como ler este mapa</h2>
        <p class="muted">
            O centro e o sistema. Cada galho e uma etapa do trabalho, na ordem em que ela acontece:
            primeiro se conecta, depois se reune contato, depois se fala, depois se escuta, e so no fim
            se le o resultado. Clique num galho para abrir a parte correspondente do manual.
        </p>
        <div class="actions">
            <a class="btn secondary" href="{{ route('manual.index') }}"><x-icon name="book" size="16" />Abrir o manual completo</a>
        </div>
    </section>

    <section class="card mindmap-card">
        <div class="mindmap">
            <p class="mindmap-root">
                <x-icon name="chat" size="22" />
                <span>Conversar com pessoas, e entender o que elas disseram</span>
            </p>

            <ul class="mindmap-branches">
                @foreach($sections as $index => $section)
                    <li class="mindmap-branch" style="--branch: var(--branch-{{ $index % 6 }});">
                        <a class="mindmap-node" href="{{ route('manual.index') }}#{{ $section['id'] }}">
                            <x-icon name="{{ $section['icon'] }}" size="18" />
                            <span>
                                <strong>{{ $section['title'] }}</strong>
                                <span class="muted">{{ $section['summary'] }}</span>
                            </span>
                        </a>
                        <ul>
                            @foreach($section['topics'] as $topic)
                                <li><span class="mindmap-leaf">{{ $topic }}</span></li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="card">
        <h2>O caminho mais curto para começar</h2>
        <p class="muted">Se for a primeira vez, faça nesta ordem e pare a cada passo para conferir.</p>
        <ol class="mindmap-path">
            <li>Conectar o WhatsApp e ver o status ficar conectado.</li>
            <li>Cadastrar a chave do provedor de IA e usar o botão de testar.</li>
            <li>Importar uma planilha pequena, so para ver a validação funcionando.</li>
            <li>Criar um modelo de mensagem e abrir a previa.</li>
            <li>Montar um lote com poucos contatos e validar antes de disparar.</li>
            <li>Enviar, acompanhar em Processamento e responder na tela de Conversas.</li>
            <li>So depois disso ligar a automação &mdash; e primeiro sem envio automático.</li>
        </ol>
    </section>

    {{--
        Três coisas que ninguém descobre navegando: elas não têm tela própria e só
        aparecem quando já deram errado. Ficam aqui porque o mapa é o lugar onde
        se procura o que existe, antes de saber o nome do que se procura.
    --}}
    <section class="card">
        <h2>O que acontece sem ninguém clicar</h2>
        <p class="muted">
            Três comportamentos rodam sozinhos. Nenhum deles tem tela própria, e e por isso que
            costumam ser descobertos tarde.
        </p>
        <ul class="mindmap-path">
            <li>
                <strong>Ninguém fica sem resposta.</strong> Passado o tempo configurado, o sistema tenta
                responder de verdade com a IA; so quando não da e que manda o aviso de recebimento.
                <a href="{{ route('manual.index') }}#atendimento">Ver no manual</a>
            </li>
            <li>
                <strong>A sessão do WhatsApp volta sozinha</strong> depois que o servidor reinicia.
                <a href="{{ route('manual.index') }}#preparar">Ver no manual</a>
            </li>
            <li>
                <strong>A IA consulta a base de conhecimento</strong> antes de redigir &mdash; se a chave
                geral, a base e o vínculo com o fluxo estiverem valendo ao mesmo tempo.
                <a href="{{ route('manual.index') }}#inteligencia">Ver no manual</a>
            </li>
        </ul>
    </section>
</x-layouts.app>
