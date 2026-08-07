<x-layouts.app title="Manual de uso" breadcrumbs="Inicio / Manual / Manual de uso">
    <article class="manual">
        <section class="card manual-intro">
            <h2>Para que serve este sistema</h2>
            <p>
                Ele faz três coisas, nesta ordem: guarda uma base de contatos, fala com essas pessoas
                pelo WhatsApp e organiza o que elas respondem. Tudo o mais no menu existe para apoiar
                uma dessas três.
            </p>
            <p class="muted">
                Este manual acompanha o sistema, então os números que aparecem aqui são os que estão
                valendo agora, lidos das configurações. Se alguém mudar um limite, esta página muda junto.
            </p>
            <div class="actions">
                <a class="btn" href="{{ route('manual.mind-map') }}"><x-icon name="mind-map" size="16" />Ver o mapa mental</a>
            </div>
        </section>

        <nav class="card manual-toc" aria-label="Índice do manual">
            <h2>Índice</h2>
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

            <h3>Conexão do WhatsApp</h3>
            <p>
                Em <strong>Sistema &rsaquo; Conexão WhatsApp</strong>. O sistema mostra um QR Code; você
                le com o celular que vai enviar as mensagens. Enquanto o status não estiver conectado,
                nenhum envio sai da fila &mdash; ele fica aguardando, e não se perde.
            </p>
            <p class="muted">
                O celular precisa continuar ligado e com internet. Se a sessão cair, a mesma tela tem
                <em>Reconectar</em>; so use <em>Limpar sessão</em> quando for trocar de número, porque
                isso obriga a ler o QR Code de novo.
            </p>
            <p class="muted">
                Quando o servidor reinicia &mdash; atualização, manutenção, queda de energia &mdash; a
                sessão volta sozinha, sem ninguém precisar abrir esta tela. Antes não voltava: o sistema
                respondia normalmente por fora enquanto os envios ficavam parados, e nada avisava. Se
                mesmo assim o status não ficar conectado em alguns minutos, e porque o WhatsApp derrubou
                a sessão do outro lado, e ai o QR Code aparece aqui de novo.
            </p>

            <h3>Provedor de IA</h3>
            <p>
                Em <strong>Inteligência &rsaquo; Provedor de IA</strong>. Escolha o provedor na lista, o
                modelo na lista que aparece em seguida e cole a chave. A chave e guardada cifrada e nunca
                mais e exibida: a tela mostra so os quatro últimos caracteres, para você conferir que e a
                certa sem que ela apareça na tela de novo.
            </p>
            <p>
                O botão <em>Testar</em> faz uma chamada de verdade e pequena ao provedor. Se ela falhar,
                o erro aparece ali, antes de a IA ser usada em qualquer conversa.
            </p>
            <div class="alert warning">
                <x-icon name="alert" size="18" />
                <span>
                    Depois de salvar ou trocar a chave, o processo de fila precisa ser reiniciado. Ele
                    e um processo longo e carrega a configuração uma vez, ao subir: sem reiniciar, ele
                    continua usando a chave antiga.
                </span>
            </div>

            <h3>Usuários e perfis</h3>
            <p>
                Em <strong>Sistema &rsaquo; Usuários</strong>. São três perfis:
            </p>
            <ul>
                <li><strong>Administrador</strong> &mdash; tudo, inclusive ligar automação e ver dado identificado.</li>
                <li><strong>Operador</strong> &mdash; opera: importa, envia, atende, le conteúdo. Não aprova base de conhecimento nem exporta dado identificado.</li>
                <li><strong>Consulta</strong> &mdash; le número. Nunca ve o texto que a pessoa escreveu nem quem escreveu.</li>
            </ul>
            <p class="muted">
                O perfil e o que decide o que aparece no menu. Se um item que este manual cita não
                estiver no seu menu, e permissão, não defeito.
            </p>

            <h3>Configurações gerais</h3>
            <p>
                Em <strong>Sistema &rsaquo; Configurações</strong> e <strong>Configurações de envio</strong>.
                Ali ficam nome do sistema, formatos de data, ritmo de disparo e janelas de horário. Vale
                conferir antes do primeiro envio, porque o ritmo padrão e conservador de propósito.
            </p>
        </section>

        {{-- 2 --}}
        <section class="card manual-section" id="contatos">
            <h2><x-icon name="users" />Reunir os contatos</h2>

            <h3>Importar uma planilha</h3>
            <p>
                Em <strong>Contatos &rsaquo; Importações</strong>. Baixe o modelo na própria tela, preencha
                e envie. A importação acontece em duas etapas, e isso e proposital: primeiro o sistema
                <em>valida</em> e mostra o que encontrou &mdash; quantas linhas entram, quantas são
                duplicadas, quantas tem telefone inválido &mdash; e so depois você <em>confirma</em>.
                Nada e gravado enquanto você não confirmar.
            </p>

            <h3>Etiquetas</h3>
            <p>
                Em <strong>Contatos &rsaquo; Etiquetas</strong>. Servem para separar públicos sem criar
                colunas novas. Uma etiqueta pode ser aplicada a vários contatos de uma vez pela ação em
                massa da lista de contatos.
            </p>

            <h3>Não contatar</h3>
            <p>
                Um contato marcado como <strong>não contatar</strong> e excluído de qualquer envio, em
                qualquer lote, sem exceção e sem aviso. A marca pode vir de três lugares: alguém marcou
                a mão, a pessoa pediu para sair pelo WhatsApp, ou a importação trouxe a marca.
            </p>
            <p class="muted">
                Desmarcar e possível e fica registrado na auditoria com o nome de quem desmarcou. Não
                desmarque quem pediu para sair.
            </p>
        </section>

        {{-- 3 --}}
        <section class="card manual-section" id="envios">
            <h2><x-icon name="send" />Falar com muita gente</h2>
            <p>Um disparo passa sempre pelos mesmos quatro passos.</p>

            <h3>1. Modelo de mensagem</h3>
            <p>
                Em <strong>Envios &rsaquo; Modelos</strong>. O texto pode ter variáveis, como o primeiro
                nome, que o sistema troca por contato na hora do envio. A tela tem <em>Previa</em>: use,
                porque e onde se descobre que a variável estava escrita errada.
            </p>

            <h3>2. Lote ou campanha</h3>
            <p>
                Em <strong>Envios &rsaquo; Lotes e campanhas</strong>. O lote junta um modelo com uma
                seleção de contatos. Você escolhe os contatos pelos mesmos filtros da tela de contatos.
            </p>

            <h3>3. Validar antes de disparar</h3>
            <p>
                O botão <em>Validar</em> separa quem esta apto de quem não esta e diz o motivo de cada
                exclusão: sem telefone, telefone inválido, marcado como não contatar, duplicado no
                próprio lote. A lista de inaptos pode ser exportada. Este passo e o que evita descobrir
                o problema depois de a mensagem ter saido.
            </p>

            <h3>4. Processamento</h3>
            <p>
                Em <strong>Envios &rsaquo; Processamento</strong>. Aqui se <em>inicia</em>, <em>pausa</em>,
                <em>retoma</em> e <em>interrompe</em>. O envio respeita um intervalo entre mensagens e a
                janela de horário configurada. Pausar não perde o lote: ele retoma de onde parou.
            </p>
            <p class="muted">
                <strong>Histórico de mensagens</strong> guarda o que foi enviado, para quem, quando, e
                as tentativas de cada destinatário, inclusive as que falharam e o motivo.
            </p>
        </section>

        {{-- 4 --}}
        <section class="card manual-section" id="atendimento">
            <h2><x-icon name="inbox" />Atender quem responde</h2>
            <p>
                Em <strong>Atendimento &rsaquo; Conversas</strong>. Toda mensagem recebida e gravada antes
                de qualquer análise. Isso importa saber: se a IA estiver fora do ar, ou o provedor
                recusar, a mensagem da pessoa já esta salva.
            </p>
            <ul>
                <li><strong>Responder</strong> &mdash; a resposta sai pelo mesmo número conectado.</li>
                <li><strong>Assumir</strong> &mdash; marca a conversa como sua, para duas pessoas não responderem juntas.</li>
                <li><strong>Nota interna</strong> &mdash; fica so no sistema. A pessoa do outro lado não ve.</li>
                <li><strong>Etiqueta e prioridade</strong> &mdash; organizam a fila sem mandar nada.</li>
                <li><strong>Arquivar</strong> &mdash; tira da caixa sem apagar nada.</li>
            </ul>

            <h3>Sugestões de resposta</h3>
            <p>
                Em <strong>Atendimento &rsaquo; Sugestões de resposta</strong>. A IA redige uma proposta e
                para ali. A sugestão <strong>não sai sozinha</strong>: alguém le, aprova, edita ou rejeita.
                Quando um texto sugerido e enviado, a mensagem fica marcada como tal, com o nome de quem
                aprovou.
            </p>
            <p>
                A lista tem <strong>Aprovar</strong> ao lado de cada sugestão e
                <strong>Aprovar todas as pendentes</strong> para a fila inteira. Sugestão
                <strong>obsoleta</strong> fica de fora das duas: ela foi escrita para uma mensagem que já
                não e a última da conversa, ou para uma conversa que já foi respondida por outro caminho.
                Enviar assim seria falar duas vezes sobre a mesma coisa.
            </p>

            <h3>Quando a pessoa manda áudio</h3>
            <p>
                O áudio e registrado na conversa como qualquer outra mensagem, mas o sistema
                <strong>ainda não escuta</strong>: ele responde na hora pedindo que a pessoa escreva. O
                pedido sai uma vez por conversa, para não virar insistência com quem prefere falar.
            </p>

            <h3>Ninguém fica sem resposta</h3>
            <p>
                A automação tem várias saídas legítimas que terminam em silêncio &mdash; pesquisa
                encerrada, conversa encaminhada para gente, resposta que o sistema não entendeu. Cada uma
                faz sentido sozinha, e o efeito somado e sempre o mesmo para quem escreveu.
            </p>
            <p>
                Passado o tempo configurado sem retorno, o sistema <strong>primeiro tenta responder de
                verdade</strong>: a IA le o que a pessoa disse, com a taxonomia e a base de conhecimento,
                e escreve. So quando isso não da &mdash; confiança baixa, pedido de revisão, texto
                reprovado &mdash; e que sai o aviso de recebimento.
            </p>
            <p class="muted">
                O aviso e o piso, nunca o primeiro recurso: manda-lo antes de tentar responder
                transformaria toda conversa em protocolo. E ele sai no máximo uma vez a cada poucas
                horas por conversa, para que três mensagens numa tarde não virem três vezes a mesma
                frase.
            </p>
            <p>
                São <strong>dois textos</strong>, e a escolha depende do tamanho da conversa. O aviso
                institucional &mdash; "nossa equipe vai ler com atenção" &mdash; pressupõe que houve o
                que ler: dito a quem acabou de escrever a primeira frase, soa como dispensa e encerra
                uma conversa que nem tinha começado. Antes do número de idas e voltas configurado sai
                um texto curto; a partir dele, o institucional.
            </p>
            <p class="muted">
                Uma ida e volta e o sistema falar e a pessoa responder. Duas mensagens nossas seguidas
                não viram duas idas e voltas, e três respostas dela também não &mdash; o que se conta e
                quantas vezes a conversa de fato voltou.
            </p>
        </section>

        {{-- 5 --}}
        <section class="card manual-section" id="pesquisa">
            <h2><x-icon name="poll" />Perguntar e escutar</h2>
            <p>
                A pesquisa conversacional e a parte que faz perguntas sozinha: pede permissão, faz as
                perguntas cadastradas uma a cada resposta, agradece e para.
            </p>

            <div class="actions">
                <a class="btn" href="{{ route('manual.survey-start') }}"><x-icon name="poll" size="16" />Ver os cinco passos para iniciar uma pesquisa</a>
            </div>

            <h3>Como uma pesquisa começa</h3>
            <p>
                Uma pesquisa não e iniciada numa tela própria: ela nasce de um <strong>lote vinculado a um
                fluxo</strong>. O campo fica no formulário do lote, em <strong>"3. Resposta automática"</strong>,
                e e o passo que transforma um disparo em pesquisa.
            </p>
            <p class="alert warning">
                Lote sem fluxo vinculado envia normalmente e não abre pesquisa nenhuma &mdash; quem responder
                cai em atendimento humano. Como o disparo funciona, nada indica o erro.
            </p>

            <h3>Fluxo e perguntas</h3>
            <p>
                Em <strong>Pesquisa &rsaquo; Fluxos conversacionais</strong>. Um fluxo tem os textos de
                permissão, recusa e agradecimento, mais um conjunto de perguntas cadastradas com o texto
                exato que será enviado. Só fluxo <strong>ativo</strong> pode ser vinculado a um lote.
            </p>
            <p>Dois campos decidem o formato da conversa:</p>
            <ul>
                <li>
                    <strong>Perguntas principais</strong> &mdash; quantas perguntas cada conversa recebe.
                    Com 1, a conversa acaba na primeira resposta; com 5, a pessoa responde cinco, uma a cada vez.
                </li>
                <li>
                    <strong>Ordem das perguntas</strong> &mdash; <em>sorteio ponderado</em> dá perguntas
                    diferentes a respondentes diferentes, cobrindo mais temas com menos perguntas por pessoa;
                    <em>sequência definida</em> faz todo mundo responder as mesmas perguntas na mesma ordem,
                    que e o que um questionário precisa para as respostas se compararem.
                </li>
            </ul>
            <p>
                O texto da pergunta aceita os mesmos placeholders da mensagem de lote. Contato sem o campo
                preenchido não recebe a pergunta: mandar <code>{cidade}</code> literal seria pior que não mandar.
            </p>

            <h3>Permissão</h3>
            <p>
                A primeira mensagem pergunta se a pessoa aceita participar. Se a resposta for negativa,
                o fluxo encerra e não insiste. Se for ambígua, o sistema não adivinha: passa para
                atendimento humano.
            </p>
            <p class="alert warning">
                Por isso a mensagem do lote precisa terminar numa pergunta de sim ou não. Se ela já trouxer
                a pergunta da pesquisa, a pessoa responde com uma opinião, o sistema lê como ambígua e a
                conversa para antes de começar.
            </p>

            <h3>Aprofundamento por IA</h3>
            <p>
                O campo <strong>perguntas de aprofundamento</strong> permite que a IA formule a próxima
                pergunta a partir do que a pessoa acabou de escrever. Ele entra <strong>depois</strong> das
                perguntas cadastradas, nunca no lugar delas: a pergunta cadastrada e igual para todo mundo
                e produz resposta comparável, que e o ponto de uma pesquisa. Zero aqui significa pesquisa
                puramente determinística, sem nenhuma chamada de IA.
            </p>

            <h3>Limites, agora</h3>
            <div class="manual-facts">
                <div>
                    <span class="muted">Mensagens automáticas por conversa</span>
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
                    <span class="muted">Automação</span>
                    <strong>{{ $operational['automation_enabled'] === '1' ? 'Ligada' : 'Desligada' }}</strong>
                </div>
                <div>
                    <span class="muted">Envio automático</span>
                    <strong>{{ $operational['auto_send_enabled'] === '1' ? 'Ligado' : 'Desligado' }}</strong>
                </div>
                <div>
                    <span class="muted">Infraestrutura de IA</span>
                    <strong>{{ $operational['ai_enabled'] === '1' ? 'Ligada' : 'Desligada' }}</strong>
                </div>
                <div>
                    <span class="muted">Base de conhecimento</span>
                    <strong>{{ $operational['knowledge_enabled'] === '1' ? 'Consultada' : 'Não consultada' }}</strong>
                </div>
                <div>
                    <span class="muted">Cobre o silêncio depois de</span>
                    <strong>{{ $operational['unanswered_after_minutes'] }} min</strong>
                </div>
                <div>
                    <span class="muted">Aviso institucional a partir de</span>
                    <strong>{{ $operational['ack_min_exchanges'] }} idas e voltas</strong>
                </div>
                <div>
                    <span class="muted">Transcrição de áudio</span>
                    <strong>{{ $operational['transcription_enabled'] === '1' ? 'Ligada' : 'Desligada' }}</strong>
                </div>
                <div>
                    <span class="muted">Ritmo de envio</span>
                    <strong>{{ $operational['max_per_minute'] }}/min &middot; {{ $operational['max_per_hour'] }}/h &middot; {{ $operational['max_per_day'] }}/dia</strong>
                </div>
            </div>
            <p class="muted">
                O teto diário decide o tamanho útil de um lote: o que passar disso continua no dia
                seguinte, sem erro e sem aviso. E resposta que chega fora da janela não recebe a pergunta
                seguinte &mdash; não ha nova tentativa quando a janela abre, então a conversa so anda se a
                pessoa escrever de novo dentro do horário.
            </p>
            <p class="muted">
                São dois interruptores separados, e não um. <strong>Automação</strong> ligada com
                <strong>envio automático</strong> desligado faz o sistema avaliar e registrar tudo sem
                mandar mensagem nenhuma &mdash; e assim que se homologa com segurança.
            </p>
            @if($operational['transparency_text'] !== '')
                <p>
                    Toda mensagem automática carrega este aviso:
                    <q class="manual-quote">{{ $operational['transparency_text'] }}</q>
                </p>
            @endif

            <h3>Acompanhamento</h3>
            <p>
                Em <strong>Pesquisa &rsaquo; Pesquisa conversacional</strong> você ve cada conversa em
                andamento e em que etapa ela esta. Da para <em>pausar</em>, <em>encerrar</em> ou
                <em>assumir</em> uma conversa específica &mdash; assumir desliga a automação naquela
                conversa e ela passa a ser sua.
            </p>
        </section>

        {{-- 6 --}}
        <section class="card manual-section" id="inteligencia">
            <h2><x-icon name="sparkles" />Deixar a IA ajudar</h2>
            <p>
                A IA aqui faz duas coisas: <strong>interpreta</strong> o que foi dito e <strong>sugere</strong>
                o que responder. Ela não envia, não decide e não pública.
            </p>

            <h3>Interpretação</h3>
            <p>
                Em <strong>Inteligência &rsaquo; Interpretação por IA</strong>. Cada leitura mostra a
                confiança e o trecho de origem. Você pode <em>corrigir</em> a interpretação, e a correção
                e o que vale dali em diante. Leitura com confiança baixa vai para a fila de revisão em
                vez de entrar direto nos números.
            </p>

            <h3>Taxonomia de temas</h3>
            <p>
                Em <strong>Inteligência &rsaquo; Taxonomia de temas</strong>. E a lista fechada de temas
                que a IA pode usar. Manter essa lista curta e clara e o que faz os relatórios de temas
                servirem para alguma coisa.
            </p>
            <p>
                Cada tema tem um <strong>vocabulário</strong>, e e ele que decide de verdade: um tema so
                alcança a resposta se alguma palavra dele aparecer no que a pessoa escreveu. Por isso o
                vocabulário traz a palavra da rua &mdash; "posto", "estrada de chão", "sinal de celular"
                &mdash; e não a do documento oficial. Tema sem vocabulário existe no cadastro e nunca e
                escolhido.
            </p>
            <p class="muted">
                Se muita coisa estiver caindo em <em>Outros</em> nos relatórios, o vocabulário esta curto
                para o jeito como as pessoas daquela região escrevem. Acrescentar termo e a correção mais
                barata que existe aqui.
            </p>

            <h3>Base de conhecimento</h3>
            <p>
                Em <strong>Inteligência &rsaquo; Base de conhecimento</strong>. Documentos aprovados que a
                IA pode consultar para redigir sugestões. Um documento so passa a valer depois de
                <strong>aprovado</strong> &mdash; quem envia não e quem aprova. Use <strong>Teste de busca
                na base</strong> para ver o que a IA encontraria com uma pergunta, antes de confiar nela.
            </p>
            <p>
                Quem administra pode corrigir a ficha de uma base pelo botão <strong>Editar</strong>,
                na própria listagem ou dentro da base: nome, descrição, finalidade, política de uso
                e os fluxos que podem consulta-la. Trocar os fluxos vale na hora, então tire um
                fluxo da lista so quando quiser mesmo que ele pare de usar aquele conteúdo.
            </p>
            <p>
                <strong>Ativar e desativar continua sendo ação separada</strong>, pelo <em>Alterar
                situação</em>. Salvar o formulário nunca pública uma base &mdash; escrever a ficha e
                decidir que ela pode fundamentar resposta são coisas diferentes.
            </p>
            <p>
                Para a base ser consultada de verdade, três coisas precisam valer ao mesmo tempo: a
                <strong>chave geral de conhecimento ligada</strong>, a <strong>base ativa</strong> e a
                base <strong>vinculada ao fluxo</strong>. Faltando qualquer uma, a IA responde sem
                consultar nada &mdash; e não avisa que respondeu no escuro. E por isso que vale usar o
                <strong>Teste de busca na base</strong> depois de mexer em qualquer uma das três.
            </p>
            <p class="muted">
                Documento curto e sobre um assunto so e encontrado muito melhor que documento longo
                cobrindo tudo. Uma ficha por tema, com as palavras que a pessoa usaria na pergunta, vale
                mais que um texto completo e bem escrito.
            </p>
            <div class="alert warning">
                <x-icon name="shield" size="18" />
                <span>
                    A base de conhecimento e a fonte das respostas. As opiniões coletadas na pesquisa
                    <strong>não</strong> são usadas para responder a ninguém: elas viram número agregado,
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
                Todo número destas telas vem com o denominador visível. Uma porcentagem sozinha não diz
                se ela saiu de dez pessoas ou de dez mil.
            </p>
            <ul>
                <li><strong>Painel da pesquisa</strong> &mdash; participação, permissão, respostas e conclusão.</li>
                <li><strong>Temas mais citados</strong> &mdash; o que apareceu, quantas vezes, e o que e novo.</li>
                <li><strong>Geografia</strong> &mdash; so cidade e estado informados pelo próprio contato. O sistema não deduz onde a pessoa mora pelo DDD.</li>
                <li><strong>Demandas</strong> &mdash; problemas e pedidos. Exige permissão para ver conteúdo.</li>
                <li><strong>Qualidade das perguntas</strong> &mdash; quais perguntas as pessoas respondem e quais fazem elas abandonarem.</li>
            </ul>

            <h3>Células pequenas ficam ocultas</h3>
            <p>
                Um grupo com menos de <strong>{{ $operational['minimum_cell_size'] }}</strong> pessoas
                aparece suprimido em vez de aparecer com o número. Não e defeito de tela: com grupo
                pequeno demais, o número deixa de ser agregado e passa a apontar para pessoas.
                Zero continua sendo mostrado como zero.
            </p>
            <p class="muted">O período padrão dos relatórios e de {{ $operational['default_period_days'] }} dias.</p>

            <h3>Exportações</h3>
            <p>
                Em <strong>Envios &rsaquo; Exportações</strong>. Por padrão a exportação e
                <strong>agregada</strong>. Exportar o texto que as pessoas escreveram exige permissão
                elevada e uma justificativa escrita, que fica na auditoria junto com o nome de quem
                exportou.
            </p>
            <p class="muted">
                Arquivos gerados expiram em {{ $operational['export_expiration_hours'] }} horas.
                Exportação grande vai para a fila &mdash; a tela avisa quando o arquivo estiver pronto.
            </p>
        </section>

        {{-- 8 --}}
        <section class="card manual-section" id="governanca">
            <h2><x-icon name="shield" />Cuidar dos dados</h2>
            <ul>
                <li><strong>Governança</strong> &mdash; o que o sistema guarda, com que base e por quanto tempo.</li>
                <li><strong>Auditoria</strong> &mdash; quem fez o que e quando. Toda ação sensível passa por aqui.</li>
                <li><strong>Saúde do sistema</strong> &mdash; filas, jobs falhos, conexão. E a primeira tela a abrir quando algo parece travado.</li>
                <li><strong>Manutenção</strong> &mdash; recontagem de contadores, recuperação de itens presos e aplicação da retenção.</li>
            </ul>
            <p class="muted">
                Nada em Manutenção roda sozinho: cada ação e um botão, com confirmação, e fica registrada.
            </p>
        </section>

        {{-- 9 --}}
        <section class="card manual-section" id="limites">
            <h2><x-icon name="alert" />O que o sistema não faz</h2>
            <p>
                Estes limites estão no código. Não dependem de alguém lembrar deles no dia do envio.
            </p>
            <ul>
                <li>Não se passa por pessoa humana. Mensagem automática vai identificada como automática.</li>
                <li>Não promete cargo, benefício, favor ou resultado.</li>
                <li>Não conversa sem fim: ha limite de turnos, de tempo e de tentativas.</li>
                <li>Respeita pedido de saída na hora, sem exigir palavra exata nem segunda confirmação.</li>
                <li>Não usa o que uma pessoa escreveu para responder a outra.</li>
                <li>Não monta mensagem individual a partir de caracteristica sensível.</li>
                <li>Não deduz localização. Cidade e estado são os que a própria pessoa informou.</li>
                <li>Encaminha situação sensível para atendimento humano em vez de responder sozinho.</li>
                <li>Não deixa mensagem recebida sem retorno: se não consegue responder, avisa que recebeu.</li>
            </ul>

            <h3>O que ainda não funciona</h3>
            <p>
                Isto não e limite de propósito, e coisa por resolver. Fica aqui para ninguém contar com
                o que não existe.
            </p>
            <ul>
                <li>
                    <strong>Áudio não vira texto.</strong> A nota de voz e registrada na conversa, e o
                    sistema pede que a pessoa escreva. A transcrição esta pronta e desligada: o que falta
                    e conseguir baixar o arquivo do WhatsApp.
                </li>
            </ul>
        </section>
    </article>
</x-layouts.app>
