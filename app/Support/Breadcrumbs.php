<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Caminho de migalhas.
 *
 * Cada tela declara a trilha como texto (`breadcrumbs="Início / Contatos"`) e
 * aqui fica o que cada segmento aponta. Manter isso num só lugar e o que permite
 * um teste conferir que nenhuma tela ficou com a trilha muda — verificação que
 * não existia enquanto o mapa morava dentro do layout, e que pegou treze telas
 * sem entrada nenhuma no dia em que foi escrita.
 *
 * Duas defesas contra o tipo de erro que já aconteceu aqui:
 *
 * 1. A busca ignora acento e caixa. Uma revisão de ortografia que trocou
 *    `Inicio` por `Início` nas telas, sem trocar nas chaves, apagou o link de
 *    duas telas sem que nada quebrasse — a trilha continuou aparecendo, so que
 *    sem link. Comparar sem acento faz as duas formas encontrarem a mesma
 *    entrada.
 *
 * 2. Trilha sem entrada não fica muda: o primeiro segmento vira link para o
 *    início mesmo assim. E o link que mais importa, e uma tela nova passa a
 *    nascer utilizável enquanto ninguém escreveu a entrada dela.
 *
 * A regra do último segmento e absoluta: a página atual nunca tem link. Link
 * para onde já se esta e ruído, e o teste recusa qualquer entrada que faça isso.
 */
class Breadcrumbs
{
    /**
     * Trilha completa => nome da rota de cada segmento, na mesma ordem.
     * `null` significa segmento sem link.
     *
     * @var array<string, list<string|null>>
     */
    private const MAP = [
        'Inicio / Perfil' => ['dashboard', null],
        'Inicio / Dashboard' => ['dashboard', null],
        'Relatorios / Visao geral' => ['admin.reports.index', null],
        'Relatorios / Erros' => ['admin.reports.index', null],
        'Relatorios / Nao enviados' => ['admin.reports.index', null],
        'Relatorios / Limites' => ['admin.reports.index', null],
        'Relatorios / Lotes' => ['admin.reports.index', null],
        'Relatorios / Modelos' => ['admin.reports.index', null],
        'Relatorios / Conversas' => ['admin.reports.index', null],
        'Relatorios / Tentativas' => ['admin.reports.index', null],
        'Relatorios / Contatos' => ['admin.reports.index', null],
        'Relatorios / Mensagens' => ['admin.reports.index', null],
        'Inicio / Contatos / Etiquetas' => ['dashboard', 'admin.contacts.index', null],
        'Inicio / Contatos / Etiquetas / Editar' => ['dashboard', 'admin.contacts.index', 'admin.tags.index', null],
        'Inicio / Contatos / Importar' => ['dashboard', 'admin.contacts.index', null],
        'Inicio / Contatos / Etiquetas / Nova' => ['dashboard', 'admin.contacts.index', 'admin.tags.index', null],
        'Inicio / Contatos' => ['dashboard', null],
        'Inicio / Contatos / Detalhes' => ['dashboard', 'admin.contacts.index', null],
        'Inicio / Contatos / Novo' => ['dashboard', 'admin.contacts.index', null],
        'Inicio / Contatos / Editar' => ['dashboard', 'admin.contacts.index', null],
        'Inicio / Contatos / Importações' => ['dashboard', 'admin.contacts.index', null],
        'Inicio / WhatsApp / Eventos' => ['dashboard', null, null],
        'Inicio / Contatos / Importações / Detalhes' => ['dashboard', 'admin.contacts.index', 'admin.contacts.imports.index', null],
        'Operacao / Monitoramento' => [null, null],
        'Inicio / Configuracoes' => ['dashboard', null],
        'Atendimento / Conversas / Conversa' => [null, 'admin.conversations.index', null],
        'Atendimento / Conversas / Iniciar conversa' => [null, 'admin.conversations.index', null],
        'Inicio / WhatsApp / Conexao' => ['dashboard', null, null],
        'Operacao / Monitoramento / Jobs falhos' => [null, 'admin.monitoring.index', null],
        'Mensagens / Processamento' => [null, null],
        'Atendimento / Conversas' => [null, null],
        'Mensagens / Processamento / Tentativas' => [null, 'admin.message-processing.index', null],
        'Inicio / Atendimento de entrada' => ['dashboard', null],
        'Inicio / Atendimento de entrada / Perfis' => ['dashboard', 'admin.inbound-attendance.index', null],
        'Inicio / Atendimento de entrada / Perfis / Novo' => ['dashboard', 'admin.inbound-attendance.index', 'admin.inbound-attendance.profiles.index', null],
        'Inicio / Atendimento de entrada / Perfis / Editar' => ['dashboard', 'admin.inbound-attendance.index', 'admin.inbound-attendance.profiles.index', null],
        'Inicio / Pesquisa conversacional / Automacao' => ['dashboard', null, null],
        'Inicio / Pesquisa conversacional / Automacao / Detalhes' => ['dashboard', null, 'admin.conversation-automation.index', null],
        'Inicio / Pesquisa conversacional / Automacao / Configuracao' => ['dashboard', null, 'admin.conversation-automation.index', null],
        'Historicos / Mensagens / Detalhe' => [null, 'admin.histories.messages.index', null],
        'Inicio / Mensagens / Modelos / Detalhes' => ['dashboard', null, 'admin.message-templates.index', null],
        'Mensagens / Configuracoes de envio' => [null, null],
        'Contatos / Historico de mensagens' => ['admin.contacts.index', null],
        'Inicio / Mensagens / Modelos / Editar' => ['dashboard', null, 'admin.message-templates.index', null],
        'Historicos / Mensagens' => [null, null],
        'Inicio / Mensagens / Modelos / Novo' => ['dashboard', null, 'admin.message-templates.index', null],
        'Inicio / Mensagens / Modelos' => ['dashboard', null, null],
        'Inicio / Auditoria / Detalhes' => ['dashboard', 'admin.audit-logs.index', null],
        'Inicio / Mensagens / Modelos / Previa' => ['dashboard', null, 'admin.message-templates.index', null],
        'Operacao / Manutencao' => [null, null],
        'Inicio / Mensagens / Lotes' => ['dashboard', null, null],
        'Inicio / Auditoria' => ['dashboard', null],
        'Inicio / Mensagens / Campanha / Editar' => ['dashboard', null, 'admin.message-batches.index', null],
        'Inicio / Mensagens / Lotes / Editar' => ['dashboard', null, 'admin.message-batches.index', null],
        'Inicio / Mensagens / Lotes / Detalhes' => ['dashboard', null, 'admin.message-batches.index', null],
        'Inicio / Mensagens / Campanha' => ['dashboard', null, null],
        'Inicio / Mensagens / Lotes / Novo' => ['dashboard', null, 'admin.message-batches.index', null],
        'Inicio / Mensagens / Lotes / Destinatarios' => ['dashboard', null, 'admin.message-batches.index', null],
        'Operacao / Exportacoes' => [null, null],
        'Operacao / Exportacoes / Detalhe' => [null, 'admin.report-exports.index', null],
        'Inicio / Usuarios' => ['dashboard', null],
        'Inicio / Usuarios / Detalhes' => ['dashboard', 'admin.users.index', null],
        'Inicio / Usuarios / Editar' => ['dashboard', 'admin.users.index', null],
        'Inicio / Usuarios / Cadastrar' => ['dashboard', 'admin.users.index', null],
        'Inicio / Pesquisa conversacional / Fluxos' => ['dashboard', null, null],
        'Inicio / Pesquisa conversacional / Fluxos / Novo' => ['dashboard', null, 'admin.conversation-flows.index', null],
        'Inicio / Pesquisa conversacional / Fluxos / Editar' => ['dashboard', null, 'admin.conversation-flows.index', null],
        'Inicio / Pesquisa conversacional / Fluxos / Detalhes' => ['dashboard', null, 'admin.conversation-flows.index', null],
        'Inicio / Pesquisa conversacional / Fluxos / Nova pergunta' => ['dashboard', null, 'admin.conversation-flows.index', null],
        'Inicio / Pesquisa conversacional / Fluxos / Editar pergunta' => ['dashboard', null, 'admin.conversation-flows.index', null],
        'Inicio / Pesquisa conversacional / Interpretacao' => ['dashboard', null, null],
        'Inicio / Pesquisa conversacional / Interpretacao / Detalhes' => ['dashboard', null, 'admin.ai-insights.index', null],
        'Inicio / Pesquisa conversacional / Temas' => ['dashboard', null, null],
        'Inicio / Pesquisa conversacional / Temas / Novo' => ['dashboard', null, 'admin.insight-topics.index', null],
        'Inicio / Pesquisa conversacional / Temas / Editar' => ['dashboard', null, 'admin.insight-topics.index', null],
        'Inicio / Pesquisa conversacional / Monitoramento de IA' => ['dashboard', null, null],
        'Inicio / Pesquisa conversacional / Sugestoes' => ['dashboard', null, null],
        'Inicio / Pesquisa conversacional / Sugestoes / Detalhes' => ['dashboard', null, 'admin.reply-suggestions.index', null],
        'Inicio / Base de conhecimento' => ['dashboard', null],
        'Inicio / Base de conhecimento / Nova base' => ['dashboard', 'admin.knowledge.bases.index', null],
        'Inicio / Base de conhecimento / Editar base' => ['dashboard', 'admin.knowledge.bases.index', null],
        'Inicio / Base de conhecimento / Base' => ['dashboard', 'admin.knowledge.bases.index', null],
        'Inicio / Base de conhecimento / Base / Novo documento' => ['dashboard', 'admin.knowledge.bases.index', null, null],
        'Inicio / Base de conhecimento / Base / Documento' => ['dashboard', 'admin.knowledge.bases.index', null, null],
        'Inicio / Base de conhecimento / Teste de busca' => ['dashboard', 'admin.knowledge.bases.index', null],
        'Inicio / Manual / Manual de uso' => ['dashboard', null, null],
        'Inicio / Manual / Mapa mental' => ['dashboard', 'manual.index', null],
        // Etapa 9E: relatórios analíticos.
        'Inicio / Relatorios / Painel da pesquisa' => ['dashboard', null, null],
        'Inicio / Relatorios / Temas' => ['dashboard', 'admin.analytics.dashboard', null],
        'Inicio / Relatorios / Geografia' => ['dashboard', 'admin.analytics.dashboard', null],
        'Inicio / Relatorios / Demandas' => ['dashboard', 'admin.analytics.dashboard', null],
        'Inicio / Relatorios / Qualidade da IA' => ['dashboard', 'admin.analytics.dashboard', null],
        'Inicio / Relatorios / Qualidade das perguntas' => ['dashboard', 'admin.analytics.dashboard', null],
        'Inicio / Relatorios / Governanca' => ['dashboard', null, null],
        'Inicio / Configuracoes / Provedor de IA' => ['dashboard', null, null],
        'Inicio / Sistema / Meta API' => ['dashboard', null, null],
        'Inicio / Base de conhecimento / Importar' => ['dashboard', 'admin.knowledge.bases.index', null],
        'Inicio / Pesquisa conversacional / Temas / Importar' => ['dashboard', null, 'admin.insight-topics.index', null],
        'Inicio / Manual / Manual de uso' => ['dashboard', null, null],
        'Inicio / Manual / Mapa mental' => ['dashboard', 'manual.index', null],
        'Inicio / Manual / Iniciar uma pesquisa' => ['dashboard', 'manual.index', null],
        'Atendimento / Conversas / Iniciar conversa' => [null, 'admin.conversations.index', null],
    ];

    /**
     * Resolve a trilha em segmentos com o destino de cada um.
     *
     * @return list<array{texto: string, rota: string|null}>
     */
    public static function for(string $trail): array
    {
        $segmentos = array_map('trim', explode('/', $trail));
        $rotas = self::rotasDe($trail);
        $total = count($segmentos);

        return array_values(array_map(
            static function (string $texto, int $posicao) use ($rotas, $total): array {
                $rota = $rotas[$posicao] ?? null;

                // O último segmento e a página atual: nunca tem link, mesmo que
                // o mapa diga o contrário.
                if ($posicao === $total - 1 || $rota === null || ! Route::has($rota)) {
                    return ['texto' => $texto, 'rota' => null];
                }

                return ['texto' => $texto, 'rota' => $rota];
            },
            $segmentos,
            array_keys($segmentos)
        ));
    }

    /**
     * @return list<string|null>
     */
    private static function rotasDe(string $trail): array
    {
        $chave = self::normalizar($trail);

        foreach (self::MAP as $mapeada => $rotas) {
            if (self::normalizar($mapeada) === $chave) {
                return $rotas;
            }
        }

        // Sem entrada: pelo menos o primeiro segmento leva ao início.
        $segmentos = explode('/', $trail);
        $inicio = self::normalizar($segmentos[0] ?? '');

        return $inicio === 'inicio' ? ['dashboard'] : [];
    }

    /** Sem acento e sem caixa: `Início` e `Inicio` são a mesma chave. */
    private static function normalizar(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    /** @return array<string, list<string|null>> */
    public static function todas(): array
    {
        return self::MAP;
    }
}
