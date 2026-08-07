<?php

namespace App\Http\Controllers;

use App\Models\SendingSetting;
use App\Services\SystemSettingService;
use Illuminate\Contracts\View\View;

/**
 * Manual de uso.
 *
 * Duas telas sobre a mesma coisa: o manual, que se le de cima a baixo, e o
 * mapa mental, que mostra o sistema inteiro numa página so. O roteiro das
 * seções fica aqui, e não duplicado nas duas views, porque duas listas iguais
 * mantidas a mão divergem na primeira alteração.
 *
 * Não ha permissão específica: quem entrou no sistema pode ler o manual do
 * sistema. Exigir permissão para ler a documentação esconderia justamente de
 * quem esta começando.
 */
class ManualController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function index(): View
    {
        return view('manual.index', [
            'sections' => $this->sections(),
            'operational' => $this->operational(),
        ]);
    }

    public function mindMap(): View
    {
        return view('manual.mind-map', [
            'sections' => $this->sections(),
        ]);
    }

    /**
     * Mapa dos cinco passos de uma pesquisa.
     *
     * Separado do mapa geral porque responde outra pergunta. O mapa geral
     * responde "o que este sistema faz"; este responde "o que eu clico agora
     * para uma pesquisa sair". Quem esta com a pesquisa para começar não quer
     * ler o sistema inteiro para achar os cinco passos no meio.
     */
    public function surveyStart(): View
    {
        return view('manual.iniciar-pesquisa', [
            'steps' => $this->surveySteps(),
            'operational' => $this->operational(),
        ]);
    }

    /**
     * Os cinco passos, na ordem de execução.
     *
     * O terceiro carrega um aviso porque e o passo que as pessoas pulam: sem o
     * vínculo com o fluxo, o lote envia e a pesquisa não acontece — e nada
     * indica o erro, porque o disparo funciona.
     *
     * @return list<array{passo: int, icon: string, title: string, where: string, summary: string, topics: list<string>, warning: ?string}>
     */
    private function surveySteps(): array
    {
        return [
            [
                'passo' => 1,
                'icon' => 'flow',
                'title' => 'Ter um fluxo ativo',
                'where' => 'Pesquisa › Fluxos conversacionais',
                'summary' => 'O fluxo guarda as perguntas e decide como a conversa anda.',
                'topics' => [
                    'Situação precisa ser Ativo, senão ele nem aparece para vincular',
                    'Ao menos uma pergunta ativa cadastrada',
                    'Perguntas principais: quantas cada conversa recebe',
                    'Ordem: sorteio ponderado ou sequência definida',
                ],
                'warning' => null,
            ],
            [
                'passo' => 2,
                'icon' => 'layers',
                'title' => 'Criar o lote',
                'where' => 'Envios › Lotes e campanhas › Novo',
                'summary' => 'Quem recebe e qual e a mensagem que abre a conversa.',
                'topics' => [
                    'Seleção manual, filtrada ou amostra aleatória',
                    'Mensagem por modelo ou avulsa',
                    'Placeholders personalizam pelo cadastro do contato',
                    'A mensagem precisa terminar pedindo autorização',
                ],
                'warning' => 'Se a mensagem já trouxer a pergunta da pesquisa, a pessoa responde com uma opinião, o sistema lê como resposta ambígua e manda para atendimento humano. Termine com uma pergunta de sim ou não.',
            ],
            [
                'passo' => 3,
                'icon' => 'poll',
                'title' => 'Vincular o fluxo ao lote',
                'where' => 'No formulário do lote, card "3. Resposta automática"',
                'summary' => 'É este campo que transforma um disparo em pesquisa.',
                'topics' => [
                    'Só fluxo ativo aparece na lista',
                    'A lista mostra quantas perguntas ativas cada fluxo tem',
                    'Sem fluxo, quem responder vai para atendimento humano',
                ],
                'warning' => 'É o passo mais esquecido, e o único que falha em silêncio: sem o vínculo o lote envia normalmente, e a pesquisa simplesmente não acontece.',
            ],
            [
                'passo' => 4,
                'icon' => 'send',
                'title' => 'Preparar e iniciar',
                'where' => 'Na tela do lote',
                'summary' => 'Conferência, confirmação explícita e disparo.',
                'topics' => [
                    'Conferir a prévia com a mensagem já renderizada',
                    'Marcar como preparado exige a frase de confirmação',
                    'Iniciar lote coloca o envio na fila',
                    'O ritmo respeita os limites por minuto, hora e dia',
                ],
                'warning' => null,
            ],
            [
                'passo' => 5,
                'icon' => 'chart',
                'title' => 'Acompanhar',
                'where' => 'Pesquisa › Pesquisa conversacional e Painel da pesquisa',
                'summary' => 'Em que estágio cada conversa parou, e o resultado agregado.',
                'topics' => [
                    'Processamento mostra o envio do lote',
                    'Pesquisa conversacional mostra conversa por conversa',
                    'Painel da pesquisa traz participação e conclusão',
                    'Conversa parada em atendimento humano pede alguém',
                ],
                'warning' => null,
            ],
        ];
    }

    /**
     * O roteiro do sistema, na ordem em que o trabalho acontece de verdade:
     * primeiro se conecta, depois se reune contato, depois se fala, depois se
     * escuta, e so no fim se le o resultado.
     *
     * @return list<array{id: string, icon: string, title: string, summary: string, topics: list<string>}>
     */
    private function sections(): array
    {
        return [
            [
                'id' => 'preparar',
                'icon' => 'plug',
                'title' => 'Preparar o sistema',
                'summary' => 'O que precisa estar de pe antes de qualquer mensagem sair.',
                'topics' => ['Conexão do WhatsApp', 'Provedor de IA', 'Usuários e perfis', 'Configurações gerais'],
            ],
            [
                'id' => 'contatos',
                'icon' => 'users',
                'title' => 'Reunir os contatos',
                'summary' => 'Como a base de pessoas entra no sistema e como ela se mantem limpa.',
                'topics' => ['Importar planilha', 'Conferir antes de confirmar', 'Etiquetas', 'Não contatar'],
            ],
            [
                'id' => 'envios',
                'icon' => 'send',
                'title' => 'Falar com muita gente',
                'summary' => 'Modelo, lote e processamento: o caminho de um disparo em massa.',
                'topics' => ['Modelo de mensagem', 'Lote e campanha', 'Validar antes de disparar', 'Processamento', 'Trava de reciprocidade', 'Histórico'],
            ],
            [
                'id' => 'atendimento',
                'icon' => 'inbox',
                'title' => 'Atender quem responde',
                'summary' => 'A caixa de conversas, e a garantia de que ninguém fica sem retorno.',
                'topics' => ['Conversas', 'Responder', 'Notas internas', 'Sugestões de resposta', 'Áudio recebido', 'Ninguém fica sem resposta'],
            ],
            [
                'id' => 'pesquisa',
                'icon' => 'poll',
                'title' => 'Perguntar e escutar',
                'summary' => 'A pesquisa conversacional: pedir permissão, fazer uma pergunta, parar.',
                'topics' => ['Fluxo e perguntas', 'Pedido de permissão', 'Limites da automação', 'Acompanhamento'],
            ],
            [
                'id' => 'inteligencia',
                'icon' => 'sparkles',
                'title' => 'Deixar a IA ajudar',
                'summary' => 'A IA interpreta e sugere. Quem decide e pública continua sendo pessoa.',
                'topics' => ['Interpretação', 'Taxonomia e vocabulário', 'Base de conhecimento', 'Teste de busca', 'Qualidade e monitoramento'],
            ],
            [
                'id' => 'relatorios',
                'icon' => 'chart',
                'title' => 'Ler o resultado',
                'summary' => 'Números com denominador visível, e não porcentagem solta.',
                'topics' => ['Painel da pesquisa', 'Temas e demandas', 'Qualidade das perguntas', 'Exportações'],
            ],
            [
                'id' => 'governanca',
                'icon' => 'shield',
                'title' => 'Cuidar dos dados',
                'summary' => 'O que o sistema guarda, por quanto tempo, e quem viu o que.',
                'topics' => ['Governança', 'Auditoria', 'Saúde do sistema', 'Manutenção e retenção'],
            ],
            [
                'id' => 'limites',
                'icon' => 'alert',
                'title' => 'O que o sistema não faz',
                'summary' => 'Limites que estão no código, e não apenas no combinado.',
                'topics' => ['Nunca se passa por pessoa', 'Nunca promete nada', 'Opt-out imediato', 'Sem microdirecionamento', 'O que ainda não funciona'],
            ],
        ];
    }

    /**
     * Valores operacionais lidos na hora, e não escritos no texto.
     *
     * Um manual que diz "o limite e três mensagens" vira mentira no dia em que
     * alguém muda a configuração para duas, e ninguém lembra de voltar aqui
     * para corrigir. Então o manual mostra o que esta valendo agora.
     *
     * @return array<string, string>
     */
    private function operational(): array
    {
        return [
            'max_automated_messages' => (string) $this->settings->get('conversation_automation.max_automated_messages', '-'),
            'validity_hours' => (string) $this->settings->get('conversation_automation.default_validity_hours', '-'),
            'window_start' => (string) $this->settings->get('conversation_automation.window_start', '-'),
            'window_end' => (string) $this->settings->get('conversation_automation.window_end', '-'),
            'transparency_text' => (string) $this->settings->get('conversation_automation.transparency_text', ''),
            'minimum_cell_size' => (string) $this->settings->get('analytics.minimum_cell_size', '-'),
            'default_period_days' => (string) $this->settings->get('analytics.default_period_days', '-'),
            'export_expiration_hours' => (string) $this->settings->get('analytics.export_expiration_hours', '-'),
            'automation_enabled' => (string) $this->settings->get('conversation_automation.enabled', '0'),
            'auto_send_enabled' => (string) $this->settings->get('conversation_automation.auto_send_enabled', '0'),
            'ai_enabled' => (string) $this->settings->get('ai.enabled', '0'),
            // Sem estes três, quem lê o manual não tem como saber se a IA está
            // consultando a base nem em quanto tempo o sistema cobre um
            // silêncio. São os valores que mudaram o comportamento de verdade.
            'knowledge_enabled' => (string) $this->settings->get('knowledge.enabled', '0'),
            'unanswered_after_minutes' => (string) $this->settings->get('conversation_automation.unanswered_after_minutes', '-'),
            'ack_min_exchanges' => (string) $this->settings->get('conversation_automation.unanswered_ack_min_exchanges', '-'),
            'transcription_enabled' => (string) $this->settings->get('ai.transcription.enabled', '0'),
            // Ritmo real de saída: e o que decide se um lote sai hoje ou em
            // cinco dias, e ninguém encontra isso sem abrir outra tela.
            'max_per_minute' => (string) (SendingSetting::query()->value('max_per_minute') ?? '-'),
            'max_per_hour' => (string) (SendingSetting::query()->value('max_per_hour') ?? '-'),
            'max_per_day' => (string) (SendingSetting::query()->value('max_per_day') ?? '-'),
        ];
    }
}
