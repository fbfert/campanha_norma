<?php

namespace App\Http\Controllers;

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
                'topics' => ['Modelo de mensagem', 'Lote e campanha', 'Validar antes de disparar', 'Processamento', 'Histórico'],
            ],
            [
                'id' => 'atendimento',
                'icon' => 'inbox',
                'title' => 'Atender quem responde',
                'summary' => 'A caixa de conversas, onde o contato deixa de ser linha de planilha.',
                'topics' => ['Conversas', 'Responder', 'Notas internas', 'Sugestões de resposta'],
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
                'topics' => ['Interpretação', 'Taxonomia de temas', 'Base de conhecimento', 'Qualidade e monitoramento'],
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
                'topics' => ['Nunca se passa por pessoa', 'Nunca promete nada', 'Opt-out imediato', 'Sem microdirecionamento'],
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
        ];
    }
}
