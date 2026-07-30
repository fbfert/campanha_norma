<x-layouts.app title="Manual de uso" breadcrumbs="Inicio / Manual / Manual de uso">
    <article class="manual">
        <section class="card manual-intro">
            <h2>Para que serve este sistema</h2>
            <p>
                Ele faz tres coisas, nesta ordem: guarda uma base de contatos, fala com essas pessoas
                pelo WhatsApp e organiza o que elas respondem. Tudo o mais no menu existe para apoiar
                uma dessas tres.
            </p>
            <p class="muted">
                Este manual acompanha o sistema, entao os numeros que aparecem aqui sao os que estao
                valendo agora, lidos das configuracoes. Se alguem mudar um limite, esta pagina muda junto.
            </p>
            <div class="actions">
                <a class="btn" href="{{ route('manual.mind-map') }}"><x-icon name="mind-map" size="16" />Ver o mapa mental</a>
            </div>
        </section>

        <nav class="card manual-toc" aria-label="Indice do manual">
            <h2>Indice</h2>
            <ol>
                @foreach($sections as $section)
                    <li>
                        <a href="#{{ $section['id'] }}">
                            <x-icon name="{{ $section['icon'] }}" size="18" />
                            <span>
                                <strong>{{ $section['title'] }}</strong>
                                <span class="muted">{{ $section['summary'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- 1 --}}
        <section class="card manual-section" id="preparar">
            <h2><x-icon name="plug" />Preparar o sistema</h2>
            <p>
                Nada funciona antes destas quatro coisas. Vale conferir na ordem, porque cada uma
                depende da anterior.
            </p>

            <h3>Conexao do WhatsApp</h3>
            <p>
                Em <strong>Sistema &rsaquo; Conexao WhatsApp</strong>. O sistema mostra um QR Code; voce
                le com o celular que vai enviar as mensagens. Enquanto o status nao estiver conectado,
                nenhum envio sai da fila &mdash; ele fica aguardando, e nao se perde.
            </p>
            <p class="muted">
                O celular precisa continuar ligado e com internet. Se a sessao cair, a mesma tela tem
                <em>Reconectar</em>; so use <em>Limpar sessao</em> quando for trocar de numero, porque
                isso obriga a ler o QR Code de novo.
            </p>

            <h3>Provedor de IA</h3>
            <p>
                Em <strong>Inteligencia &rsaquo; Provedor de IA</strong>. Escolha o provedor na lista, o
                modelo na lista que aparece em seguida e cole a chave. A chave e guardada cifrada e nunca
                mais e exibida: a tela mostra so os quatro ultimos caracteres, para voce conferir que e a
                certa sem que ela apareca na tela de novo.
            </p>
            <p>
                O botao <em>Testar</em> faz uma chamada de verdade e pequena ao provedor. Se ela falhar,
                o erro aparece ali, antes de a IA ser usada em qualquer conversa.
            </p>
            <div class="alert warning">
                <x-icon name="alert" size="18" />
                <span>
                    Depois de salvar ou trocar a chave, o processo de fila precisa ser reiniciado. Ele
                    e um processo longo e carrega a configuracao uma vez, ao subir: sem reiniciar, ele
                    continua usando a chave antiga.
                </span>
            </div>

            <h3>Usuarios e perfis</h3>
            <p>
                Em <strong>Sistema &rsaquo; Usuarios</strong>. Sao tres perfis:
            </p>
            <ul>
                <li><strong>Administrador</strong> &mdash; tudo, inclusive ligar automacao e ver dado identificado.</li>
                <li><strong>Operador</strong> &mdash; opera: importa, envia, atende, le conteudo. Nao aprova base de conhecimento nem exporta dado identificado.</li>
                <li><strong>Consulta</strong> &mdash; le numero. Nunca ve o texto que a pessoa escreveu nem quem escreveu.</li>
            </ul>
            <p class="muted">
                O perfil e o que decide o que aparece no menu. Se um item que este manual cita nao
                estiver no seu menu, e permissao, nao defeito.
            </p>

            <h3>Configuracoes gerais</h3>
            <p>
                Em <strong>Sistema &rsaquo; Configuracoes</strong> e <strong>Configuracoes de envio</strong>.
                Ali ficam nome do sistema, formatos de data, ritmo de disparo e janelas de horario. Vale
                conferir antes do primeiro envio, porque o ritmo padrao e conservador de proposito.
            </p>
        </section>

        {{-- 2 --}}
        <section class="card manual-section" id="contatos">
            <h2><x-icon name="users" />Reunir os contatos</h2>

            <h3>Importar uma planilha</h3>
            <p>
                Em <strong>Contatos &rsaquo; Importacoes</strong>. Baixe o modelo na propria tela, preencha
                e envie. A importacao acontece em duas etapas, e isso e proposital: primeiro o sistema
                <em>valida</em> e mostra o que encontrou &mdash; quantas linhas entram, quantas sao
                duplicadas, quantas tem telefone invalido &mdash; e so depois voce <em>confirma</em>.
                Nada e gravado enquanto voce nao confirmar.
            </p>

            <h3>Etiquetas</h3>
            <p>
                Em <strong>Contatos &rsaquo; Etiquetas</strong>. Servem para separar publicos sem criar
                colunas novas. Uma etiqueta pode ser aplicada a varios contatos de uma vez pela acao em
                massa da lista de contatos.
            </p>

            <h3>Nao contatar</h3>
            <p>
                Um contato marcado como <strong>nao contatar</strong> e excluido de qualquer envio, em
                qualquer lote, sem excecao e sem aviso. A marca pode vir de tres lugares: alguem marcou
                a mao, a pessoa pediu para sair pelo WhatsApp, ou a importacao trouxe a marca.
            </p>
            <p class="muted">
                Desmarcar e possivel e fica registrado na auditoria com o nome de quem desmarcou. Nao
                desmarque quem pediu para sair.
            </p>
        </section>

        {{-- 3 --}}
        <section class="card manual-section" id="envios">
            <h2><x-icon name="send" />Falar com muita gente</h2>
            <p>Um disparo passa sempre pelos mesmos quatro passos.</p>

            <h3>1. Modelo de mensagem</h3>
            <p>
                Em <strong>Envios &rsaquo; Modelos</strong>. O texto pode ter variaveis, como o primeiro
                nome, que o sistema troca por contato na hora do envio. A tela tem <em>Previa</em>: use,
                porque e onde se descobre que a variavel estava escrita errada.
            </p>

            <h3>2. Lote ou campanha</h3>
            <p>
                Em <strong>Envios &rsaquo; Lotes e campanhas</strong>. O lote junta um modelo com uma
                selecao de contatos. Voce escolhe os contatos pelos mesmos filtros da tela de contatos.
            </p>

            <h3>3. Validar antes de disparar</h3>
            <p>
                O botao <em>Validar</em> separa quem esta apto de quem nao esta e diz o motivo de cada
                exclusao: sem telefone, telefone invalido, marcado como nao contatar, duplicado no
                proprio lote. A lista de inaptos pode ser exportada. Este passo e o que evita descobrir
                o problema depois de a mensagem ter saido.
            </p>

            <h3>4. Processamento</h3>
            <p>
                Em <strong>Envios &rsaquo; Processamento</strong>. Aqui se <em>inicia</em>, <em>pausa</em>,
                <em>retoma</em> e <em>interrompe</em>. O envio respeita um intervalo entre mensagens e a
                janela de horario configurada. Pausar nao perde o lote: ele retoma de onde parou.
            </p>
            <p class="muted">
                <strong>Historico de mensagens</strong> guarda o que foi enviado, para quem, quando, e
                as tentativas de cada destinatario, inclusive as que falharam e o motivo.
            </p>
        </section>

        {{-- 4 --}}
        <section class="card manual-section" id="atendimento">
            <h2><x-icon name="inbox" />Atender quem responde</h2>
            <p>
                Em <strong>Atendimento &rsaquo; Conversas</strong>. Toda mensagem recebida e gravada antes
                de qualquer analise. Isso importa saber: se a IA estiver fora do ar, ou o provedor
                recusar, a mensagem da pessoa ja esta salva.
            </p>
            <ul>
                <li><strong>Responder</strong> &mdash; a resposta sai pelo mesmo numero conectado.</li>
                <li><strong>Assumir</strong> &mdash; marca a conversa como sua, para duas pessoas nao responderem juntas.</li>
                <li><strong>Nota interna</strong> &mdash; fica so no sistema. A pessoa do outro lado nao ve.</li>
                <li><strong>Etiqueta e prioridade</strong> &mdash; organizam a fila sem mandar nada.</li>
                <li><strong>Arquivar</strong> &mdash; tira da caixa sem apagar nada.</li>
            </ul>

            <h3>Sugestoes de resposta</h3>
            <p>
                Em <strong>Atendimento &rsaquo; Sugestoes de resposta</strong>. A IA redige uma proposta e
                para ali. A sugestao <strong>nao sai sozinha</strong>: alguem le, aprova, edita ou rejeita.
                Quando um texto sugerido e enviado, a mensagem fica marcada como tal, com o nome de quem
                aprovou.
            </p>
        </section>

        {{-- 5 --}}
        <section class="card manual-section" id="pesquisa">
            <h2><x-icon name="poll" />Perguntar e escutar</h2>
            <p>
                A pesquisa conversacional e a parte que faz perguntas sozinha. Ela e curta de proposito:
                pede permissao, faz uma pergunta, agradece e para.
            </p>

            <h3>Fluxo e perguntas</h3>
            <p>
                Em <strong>Pesquisa &rsaquo; Fluxos conversacionais</strong>. Um fluxo tem um pedido de
                permissao e um conjunto de perguntas. As perguntas sao cadastradas uma a uma, com o texto
                exato que sera enviado.
            </p>

            <h3>Permissao</h3>
            <p>
                A primeira mensagem pergunta se a pessoa aceita participar. Se a resposta for negativa,
                o fluxo encerra e nao insiste. Se for ambigua, o sistema nao adivinha: passa para
                atendimento humano.
            </p>

            <h3>Limites, agora</h3>
            <div class="manual-facts">
                <div>
                    <span class="muted">Mensagens automaticas por conversa</span>
                    <strong>{{ $operational['max_automated_messages'] }}</strong>
                </div>
                <div>
                    <span class="muted">Validade do fluxo</span>
                    <strong>{{ $operational['validity_hours'] }} h</strong>
                </div>
                <div>
                    <span class="muted">Janela de envio</span>
                    <strong>{{ $operational['window_start'] }} &ndash; {{ $operational['window_end'] }}</strong>
                </div>
                <div>
                    <span class="muted">Automacao</span>
                    <strong>{{ $operational['automation_enabled'] === '1' ? 'Ligada' : 'Desligada' }}</strong>
                </div>
                <div>
                    <span class="muted">Envio automatico</span>
                    <strong>{{ $operational['auto_send_enabled'] === '1' ? 'Ligado' : 'Desligado' }}</strong>
                </div>
                <div>
                    <span class="muted">Infraestrutura de IA</span>
                    <strong>{{ $operational['ai_enabled'] === '1' ? 'Ligada' : 'Desligada' }}</strong>
                </div>
            </div>
            <p class="muted">
                Sao dois interruptores separados, e nao um. <strong>Automacao</strong> ligada com
                <strong>envio automatico</strong> desligado faz o sistema avaliar e registrar tudo sem
                mandar mensagem nenhuma &mdash; e assim que se homologa com seguranca.
            </p>
            @if($operational['transparency_text'] !== '')
                <p>
                    Toda mensagem automatica carrega este aviso:
                    <q class="manual-quote">{{ $operational['transparency_text'] }}</q>
                </p>
            @endif

            <h3>Acompanhamento</h3>
            <p>
                Em <strong>Pesquisa &rsaquo; Pesquisa conversacional</strong> voce ve cada conversa em
                andamento e em que etapa ela esta. Da para <em>pausar</em>, <em>encerrar</em> ou
                <em>assumir</em> uma conversa especifica &mdash; assumir desliga a automacao naquela
                conversa e ela passa a ser sua.
            </p>
        </section>

        {{-- 6 --}}
        <section class="card manual-section" id="inteligencia">
            <h2><x-icon name="sparkles" />Deixar a IA ajudar</h2>
            <p>
                A IA aqui faz duas coisas: <strong>interpreta</strong> o que foi dito e <strong>sugere</strong>
                o que responder. Ela nao envia, nao decide e nao publica.
            </p>

            <h3>Interpretacao</h3>
            <p>
                Em <strong>Inteligencia &rsaquo; Interpretacao por IA</strong>. Cada leitura mostra a
                confianca e o trecho de origem. Voce pode <em>corrigir</em> a interpretacao, e a correcao
                e o que vale dali em diante. Leitura com confianca baixa vai para a fila de revisao em
                vez de entrar direto nos numeros.
            </p>

            <h3>Taxonomia de temas</h3>
            <p>
                Em <strong>Inteligencia &rsaquo; Taxonomia de temas</strong>. E a lista fechada de temas
                que a IA pode usar. Manter essa lista curta e clara e o que faz os relatorios de temas
                servirem para alguma coisa.
            </p>

            <h3>Base de conhecimento</h3>
            <p>
                Em <strong>Inteligencia &rsaquo; Base de conhecimento</strong>. Documentos aprovados que a
                IA pode consultar para redigir sugestoes. Um documento so passa a valer depois de
                <strong>aprovado</strong> &mdash; quem envia nao e quem aprova. Use <strong>Teste de busca
                na base</strong> para ver o que a IA encontraria com uma pergunta, antes de confiar nela.
            </p>
            <div class="alert warning">
                <x-icon name="shield" size="18" />
                <span>
                    A base de conhecimento e a fonte das respostas. As opinioes coletadas na pesquisa
                    <strong>nao</strong> sao usadas para responder a ninguem: elas viram numero agregado,
                    e nada mais.
                </span>
            </div>

            <h3>Qualidade e monitoramento</h3>
            <p>
                <strong>Qualidade da IA</strong> mostra quanto do que ela leu foi confirmado ou corrigido
                por pessoas. <strong>Monitoramento de IA</strong> mostra chamadas, falhas e custo. Se o
                custo subir sem o volume subir, e ali que aparece.
            </p>
        </section>

        {{-- 7 --}}
        <section class="card manual-section" id="relatorios">
            <h2><x-icon name="chart" />Ler o resultado</h2>
            <p>
                Todo numero destas telas vem com o denominador visivel. Uma porcentagem sozinha nao diz
                se ela saiu de dez pessoas ou de dez mil.
            </p>
            <ul>
                <li><strong>Painel da pesquisa</strong> &mdash; participacao, permissao, respostas e conclusao.</li>
                <li><strong>Temas mais citados</strong> &mdash; o que apareceu, quantas vezes, e o que e novo.</li>
                <li><strong>Geografia</strong> &mdash; so cidade e estado informados pelo proprio contato. O sistema nao deduz onde a pessoa mora pelo DDD.</li>
                <li><strong>Demandas</strong> &mdash; problemas e pedidos. Exige permissao para ver conteudo.</li>
                <li><strong>Qualidade das perguntas</strong> &mdash; quais perguntas as pessoas respondem e quais fazem elas abandonarem.</li>
            </ul>

            <h3>Celulas pequenas ficam ocultas</h3>
            <p>
                Um grupo com menos de <strong>{{ $operational['minimum_cell_size'] }}</strong> pessoas
                aparece suprimido em vez de aparecer com o numero. Nao e defeito de tela: com grupo
                pequeno demais, o numero deixa de ser agregado e passa a apontar para pessoas.
                Zero continua sendo mostrado como zero.
            </p>
            <p class="muted">O periodo padrao dos relatorios e de {{ $operational['default_period_days'] }} dias.</p>

            <h3>Exportacoes</h3>
            <p>
                Em <strong>Envios &rsaquo; Exportacoes</strong>. Por padrao a exportacao e
                <strong>agregada</strong>. Exportar o texto que as pessoas escreveram exige permissao
                elevada e uma justificativa escrita, que fica na auditoria junto com o nome de quem
                exportou.
            </p>
            <p class="muted">
                Arquivos gerados expiram em {{ $operational['export_expiration_hours'] }} horas.
                Exportacao grande vai para a fila &mdash; a tela avisa quando o arquivo estiver pronto.
            </p>
        </section>

        {{-- 8 --}}
        <section class="card manual-section" id="governanca">
            <h2><x-icon name="shield" />Cuidar dos dados</h2>
            <ul>
                <li><strong>Governanca</strong> &mdash; o que o sistema guarda, com que base e por quanto tempo.</li>
                <li><strong>Auditoria</strong> &mdash; quem fez o que e quando. Toda acao sensivel passa por aqui.</li>
                <li><strong>Saude do sistema</strong> &mdash; filas, jobs falhos, conexao. E a primeira tela a abrir quando algo parece travado.</li>
                <li><strong>Manutencao</strong> &mdash; recontagem de contadores, recuperacao de itens presos e aplicacao da retencao.</li>
            </ul>
            <p class="muted">
                Nada em Manutencao roda sozinho: cada acao e um botao, com confirmacao, e fica registrada.
            </p>
        </section>

        {{-- 9 --}}
        <section class="card manual-section" id="limites">
            <h2><x-icon name="alert" />O que o sistema nao faz</h2>
            <p>
                Estes limites estao no codigo. Nao dependem de alguem lembrar deles no dia do envio.
            </p>
            <ul>
                <li>Nao se passa por pessoa humana. Mensagem automatica vai identificada como automatica.</li>
                <li>Nao promete cargo, beneficio, favor ou resultado.</li>
                <li>Nao conversa sem fim: ha limite de turnos, de tempo e de tentativas.</li>
                <li>Respeita pedido de saida na hora, sem exigir palavra exata nem segunda confirmacao.</li>
                <li>Nao usa o que uma pessoa escreveu para responder a outra.</li>
                <li>Nao monta mensagem individual a partir de caracteristica sensivel.</li>
                <li>Nao deduz localizacao. Cidade e estado sao os que a propria pessoa informou.</li>
                <li>Encaminha situacao sensivel para atendimento humano em vez de responder sozinho.</li>
            </ul>
        </section>
    </article>
</x-layouts.app>
