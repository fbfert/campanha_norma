<?php

namespace App\Services\Cleanup;

use App\Enums\CleanupTarget;
use App\Models\CleanupItem;
use App\Models\CleanupOperation;
use App\Models\Contact;
use App\Models\ContactHistory;
use App\Models\ContactImportRow;
use App\Models\Conversation;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaignCoupon;
use App\Models\KeywordCampaignDraw;
use App\Models\KeywordCampaignParticipation;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\User;
use App\Models\WhatsAppTestMessage;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\SystemSettingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A Limpeza.
 *
 * Tira do ar a participação de uma pessoa em cada função que o sistema já
 * executou com ela, sem apagar o cadastro. Três decisões explicam o desenho:
 *
 * **A remoção e suave, mas o efeito e imediato.** O que sai daqui vai para a
 * lixeira com `deleted_at`, e volta inteiro enquanto o prazo não venceu. Só
 * que, para todo o resto do sistema, ele sumiu no instante em que saiu: painel,
 * relatório, contagem de lote e lista de sorteio param de enxergar na mesma
 * hora, porque e o escopo global do Eloquent que os filtra, e não uma cláusula
 * que alguém precise lembrar de escrever. Limpeza que só some da própria tela
 * não limpa nada.
 *
 * **O inventário e a única definição do que existe.** A tela, a execução e a
 * restauração leem a mesma lista de itens, com os mesmos identificadores. Não
 * há um lugar que decide o que mostrar e outro que decide o que apagar: isso e
 * o que impede a tela oferecer algo que a execução não sabe remover.
 *
 * **Inscrição em campanha e projeção da mensagem que a originou.** Quem limpa a
 * conversa de alguém está, no expurgo, apagando também a mensagem que gerou a
 * inscrição — e o banco leva a inscrição junto por cascata. Como isso apagaria
 * em silêncio algo que o operador escolheu manter, limpar conversa de quem tem
 * inscrição viva exige limpar a inscrição junto, e a recusa e aqui, em código.
 */
class CleanupService
{
    /**
     * Da tabela de volta ao modelo que sabe restaurá-la.
     *
     * O item guarda o nome da tabela e não a classe porque o nome da tabela e o
     * que continua verdadeiro depois de um renomeio de classe — e a lixeira
     * precisa descrever o que removeu muito depois de ter removido.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODELOS = [
        'keyword_campaign_coupons' => KeywordCampaignCoupon::class,
        'keyword_campaign_participations' => KeywordCampaignParticipation::class,
        'conversation_insights' => ConversationInsight::class,
        'conversation_flow_states' => ConversationFlowState::class,
        'conversation_messages' => ConversationMessage::class,
        'conversations' => Conversation::class,
        'message_batch_recipients' => MessageBatchRecipient::class,
        'whatsapp_test_messages' => WhatsAppTestMessage::class,
        'contact_history' => ContactHistory::class,
    ];

    /**
     * Ordem do expurgo: filho antes de pai.
     *
     * A cascata do banco daria conta sozinha, mas apagar de baixo para cima
     * mantém o que acontece visível na leitura, em vez de escondido numa
     * restrição de chave estrangeira.
     *
     * @var list<string>
     */
    private const ORDEM_EXPURGO = [
        'keyword_campaign_coupons',
        'keyword_campaign_participations',
        'conversation_insights',
        'conversation_flow_states',
        'conversation_messages',
        'conversations',
        'message_batch_recipients',
        'whatsapp_test_messages',
        'contact_history',
    ];

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
        private readonly BatchProgressService $progress,
    ) {}

    /**
     * Quantos dias um item fica na lixeira antes do expurgo.
     */
    public function diasDeRetencao(): int
    {
        $dias = (int) $this->settings->get('retention.cleanup_trash_days', 30);

        return $dias >= 1 ? $dias : 30;
    }

    /**
     * Tudo em que este contato participou, um item por participação.
     *
     * Item aqui e o que o operador reconhece — uma campanha, uma conversa, um
     * lote —, não uma linha de tabela. Ninguém escolhe apagar a mensagem 41 892;
     * escolhe apagar a conversa em que ela está.
     *
     * @return list<array{chave: string, target: CleanupTarget, nome: string, detalhe: string, quantidade: int, avisos: list<string>, soft: array<string, list<int>>, pivot_tags: list<array{tag_id: int, created_by: int|null}>, import_rows: list<int>}>
     */
    public function inventario(Contact $contact): array
    {
        return [
            ...$this->itensDeCampanha($contact),
            ...$this->itensDeConversa($contact),
            ...$this->itensDeEnvio($contact),
            ...$this->itensDeCadastro($contact),
        ];
    }

    /**
     * Executa a limpeza dos itens escolhidos.
     *
     * @param  list<string>  $chaves  identificadores vindos do inventário
     *
     * @throws ValidationException
     */
    public function limpar(Contact $contact, array $chaves, string $motivo, ?User $usuario = null): CleanupOperation
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Diga por que esta limpeza está sendo feita. O motivo vai para a auditoria e é o que explica a remoção meses depois.',
            ]);
        }

        $inventario = collect($this->inventario($contact))->keyBy('chave');
        $escolhidos = $inventario->only(array_values(array_unique($chaves)));

        if ($escolhidos->isEmpty()) {
            throw ValidationException::withMessages([
                'itens' => 'Escolha ao menos uma participação para limpar.',
            ]);
        }

        $this->recusarConversaQueLevaInscricao($inventario, $escolhidos->keys()->all());

        return DB::transaction(function () use ($contact, $escolhidos, $motivo, $usuario): CleanupOperation {
            $operacao = CleanupOperation::create([
                'contact_id' => $contact->id,
                'contact_name_snapshot' => $contact->name,
                'contact_phone_snapshot' => $contact->phone_normalized ?? $contact->phone ?? '',
                'targets' => $escolhidos->pluck('target')->map(fn (CleanupTarget $alvo): string => $alvo->value)->unique()->values()->all(),
                'reason' => $motivo,
                'items_count' => $escolhidos->count(),
                'involved_draw' => $escolhidos->contains(fn (array $item): bool => $item['envolve_sorteio'] ?? false),
                'executed_by' => $usuario?->id,
                'executed_at' => now(),
                'expires_at' => now()->addDays($this->diasDeRetencao()),
            ]);

            $lotesAfetados = [];

            foreach ($escolhidos as $item) {
                foreach ($item['soft'] as $tabela => $ids) {
                    $this->mandarParaLixeira($tabela, $ids);
                }

                foreach ($item['pivot_tags'] as $etiqueta) {
                    $contact->tags()->detach($etiqueta['tag_id']);
                }

                if ($item['import_rows'] !== []) {
                    ContactImportRow::query()->whereIn('id', $item['import_rows'])->update(['contact_id' => null]);
                }

                if ($item['target'] === CleanupTarget::Envios && $item['record_table'] === 'message_batch_recipients') {
                    $lotesAfetados[] = $item['lote_id'];
                }

                CleanupItem::create([
                    'cleanup_operation_id' => $operacao->id,
                    'target' => $item['target']->value,
                    'record_table' => $item['record_table'],
                    'record_id' => $item['record_id'],
                    'summary' => $item['nome'].($item['detalhe'] !== '' ? " — {$item['detalhe']}" : ''),
                    'restore_payload' => [
                        'soft' => $item['soft'],
                        'pivot_tags' => $item['pivot_tags'],
                        'import_rows' => $item['import_rows'],
                    ],
                ]);
            }

            $this->ressincronizarLotes($lotesAfetados);

            $this->audit->log(
                'cleanup.executed',
                "Limpeza de {$escolhidos->count()} ".($escolhidos->count() === 1 ? 'participação' : 'participações')
                    ." do contato \"{$operacao->contact_name_snapshot}\" ({$operacao->contact_phone_snapshot}).",
                $operacao,
                null,
                [
                    'motivo' => $motivo,
                    'itens' => $escolhidos->pluck('nome')->all(),
                    'envolve_sorteio' => $operacao->involved_draw,
                    'expira_em' => $operacao->expires_at->toDateTimeString(),
                ],
                $usuario,
            );

            return $operacao->fresh('items');
        });
    }

    /**
     * Devolve tudo o que uma limpeza tirou do ar.
     *
     * @throws ValidationException
     */
    public function restaurar(CleanupOperation $operacao, ?User $usuario = null): CleanupOperation
    {
        if (! $operacao->podeRestaurar()) {
            throw ValidationException::withMessages([
                'lixeira' => $operacao->restored_at !== null
                    ? 'Esta limpeza já foi restaurada.'
                    : 'O prazo desta limpeza venceu: o que estava aqui não pode mais voltar.',
            ]);
        }

        return DB::transaction(function () use ($operacao, $usuario): CleanupOperation {
            $lotesAfetados = [];

            foreach ($operacao->items()->whereNull('restored_at')->get() as $item) {
                $payload = $item->restore_payload ?? [];

                foreach ($payload['soft'] ?? [] as $tabela => $ids) {
                    $this->tirarDaLixeira($tabela, $ids);
                }

                foreach ($payload['pivot_tags'] ?? [] as $etiqueta) {
                    $operacao->contact?->tags()->syncWithoutDetaching([
                        $etiqueta['tag_id'] => ['created_by' => $etiqueta['created_by'] ?? null],
                    ]);
                }

                if (($payload['import_rows'] ?? []) !== [] && $operacao->contact_id !== null) {
                    ContactImportRow::query()
                        ->whereIn('id', $payload['import_rows'])
                        ->update(['contact_id' => $operacao->contact_id]);
                }

                if ($item->record_table === 'message_batch_recipients') {
                    $lotesAfetados[] = MessageBatchRecipient::withTrashed()->find($item->record_id)?->message_batch_id;
                }

                $item->update(['restored_at' => now()]);
            }

            $this->ressincronizarLotes(array_filter($lotesAfetados));

            $operacao->update(['restored_at' => now(), 'restored_by' => $usuario?->id]);

            $this->audit->log(
                'cleanup.restored',
                "Limpeza do contato \"{$operacao->contact_name_snapshot}\" ({$operacao->contact_phone_snapshot}) restaurada.",
                $operacao,
                null,
                ['itens' => $operacao->items_count, 'motivo_original' => $operacao->reason],
                $usuario,
            );

            return $operacao->fresh('items');
        });
    }

    /**
     * Apaga em definitivo o que passou do prazo.
     *
     * Roda pelo agendador. Depois daqui não há volta, e e por isso que o prazo
     * e a única coisa que separa a lixeira do apagamento: quem opera tem esse
     * tanto de dias para perceber o engano.
     *
     * @return array{limpezas: int, registros: int}
     */
    public function expurgarVencidas(): array
    {
        $operacoes = 0;
        $registros = 0;

        CleanupOperation::query()->vencidas()->with('items')->each(function (CleanupOperation $operacao) use (&$operacoes, &$registros): void {
            DB::transaction(function () use ($operacao, &$registros): void {
                $porTabela = [];

                foreach ($operacao->items()->whereNull('purged_at')->whereNull('restored_at')->get() as $item) {
                    foreach (($item->restore_payload['soft'] ?? []) as $tabela => $ids) {
                        $porTabela[$tabela] = [...($porTabela[$tabela] ?? []), ...$ids];
                    }

                    $item->update(['purged_at' => now()]);
                }

                foreach (self::ORDEM_EXPURGO as $tabela) {
                    $ids = array_values(array_unique($porTabela[$tabela] ?? []));

                    if ($ids !== []) {
                        $registros += self::MODELOS[$tabela]::withTrashed()->whereIn('id', $ids)->forceDelete();
                    }
                }

                $operacao->update(['purged_at' => now()]);
            });

            $operacoes++;
        });

        if ($operacoes > 0) {
            $this->audit->log(
                'cleanup.purged',
                "Expurgo da lixeira da Limpeza: {$operacoes} ".($operacoes === 1 ? 'operação' : 'operações')
                    .", {$registros} ".($registros === 1 ? 'registro' : 'registros').'.',
                null,
                null,
                ['limpezas' => $operacoes, 'registros' => $registros],
            );
        }

        return ['limpezas' => $operacoes, 'registros' => $registros];
    }

    /**
     * Inscrições em campanha, com os cupons que cada uma recebeu.
     *
     * @return list<array<string, mixed>>
     */
    private function itensDeCampanha(Contact $contact): array
    {
        $participacoes = KeywordCampaignParticipation::query()
            ->with('campaign')
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->get();

        if ($participacoes->isEmpty()) {
            return [];
        }

        $cupons = KeywordCampaignCoupon::query()
            ->whereIn('keyword_campaign_participation_id', $participacoes->pluck('id'))
            ->get()
            ->groupBy('keyword_campaign_participation_id');

        $sorteios = KeywordCampaignDraw::query()
            ->whereIn('keyword_campaign_id', $participacoes->pluck('keyword_campaign_id')->unique())
            ->get();

        $itens = [];

        foreach ($participacoes as $participacao) {
            $meusCupons = $cupons->get($participacao->id, collect());
            $avisos = [];
            $envolveSorteio = false;

            foreach ($sorteios as $sorteio) {
                if (in_array((int) $participacao->id, array_map('intval', $sorteio->result ?? []), true)) {
                    $envolveSorteio = true;
                    $avisos[] = 'Esta inscrição foi sorteada em '.$sorteio->executed_at?->format('d/m/Y H:i')
                        .'. Removê-la reescreve um resultado que já foi apurado e possivelmente divulgado.';
                }
            }

            if ($participacao->campaign?->estaCongelada()) {
                $avisos[] = 'A lista desta campanha está congelada. Remover a inscrição muda a lista e a conferência de hash passa a acusar divergência.';
            }

            if ($meusCupons->isNotEmpty()) {
                $avisos[] = 'Vão junto '.$meusCupons->count().' '.($meusCupons->count() === 1 ? 'cupom atribuído' : 'cupons atribuídos').' a esta inscrição.';
            }

            $itens[] = [
                'chave' => 'campanhas:'.$participacao->id,
                'target' => CleanupTarget::Campanhas,
                'record_table' => 'keyword_campaign_participations',
                'record_id' => $participacao->id,
                'nome' => 'Campanha "'.($participacao->campaign?->name ?? 'sem nome').'"',
                'detalhe' => 'inscrito em '.$participacao->created_at?->format('d/m/Y H:i')
                    .' pela palavra "'.$participacao->matched_keyword.'"',
                'quantidade' => 1 + $meusCupons->count(),
                'avisos' => $avisos,
                'envolve_sorteio' => $envolveSorteio,
                'soft' => [
                    'keyword_campaign_coupons' => $meusCupons->pluck('id')->all(),
                    'keyword_campaign_participations' => [$participacao->id],
                ],
                'pivot_tags' => [],
                'import_rows' => [],
            ];
        }

        return $itens;
    }

    /**
     * Uma conversa por item, com as mensagens, o fluxo e os insights dela.
     *
     * @return list<array<string, mixed>>
     */
    private function itensDeConversa(Contact $contact): array
    {
        $conversas = Conversation::query()
            ->where('contact_id', $contact->id)
            ->orderByDesc('last_message_at')
            ->get();

        if ($conversas->isEmpty()) {
            return [];
        }

        $itens = [];

        foreach ($conversas as $conversa) {
            $mensagens = ConversationMessage::query()->where('conversation_id', $conversa->id)->pluck('id')->all();
            $estados = ConversationFlowState::query()->where('conversation_id', $conversa->id)->pluck('id')->all();
            $insights = ConversationInsight::query()->where('conversation_id', $conversa->id)->pluck('id')->all();

            $inscricoes = KeywordCampaignParticipation::query()
                ->whereIn('conversation_message_id', $mensagens)
                ->count();

            $avisos = [];

            if ($insights !== []) {
                $avisos[] = 'Saem junto '.count($insights).' '.(count($insights) === 1 ? 'interpretação da IA' : 'interpretações da IA')
                    .', e o painel da pesquisa deixa de contar esta pessoa na hora.';
            }

            if ($inscricoes > 0) {
                $avisos[] = 'Esta conversa originou '.$inscricoes.' '.($inscricoes === 1 ? 'inscrição em campanha' : 'inscrições em campanha')
                    .'. A inscrição é projeção da mensagem, então ela precisa ser limpa junto — marque também o item da campanha.';
            }

            $itens[] = [
                'chave' => 'conversas:'.$conversa->id,
                'target' => CleanupTarget::Conversas,
                'record_table' => 'conversations',
                'record_id' => $conversa->id,
                'nome' => 'Conversa #'.$conversa->id,
                'detalhe' => count($mensagens).' '.(count($mensagens) === 1 ? 'mensagem' : 'mensagens')
                    .($conversa->last_message_at ? ', última em '.$conversa->last_message_at->format('d/m/Y H:i') : ''),
                'quantidade' => 1 + count($mensagens) + count($estados) + count($insights),
                'avisos' => $avisos,
                'envolve_sorteio' => false,
                'mensagens' => $mensagens,
                'soft' => [
                    'conversation_insights' => $insights,
                    'conversation_flow_states' => $estados,
                    'conversation_messages' => $mensagens,
                    'conversations' => [$conversa->id],
                ],
                'pivot_tags' => [],
                'import_rows' => [],
            ];
        }

        return $itens;
    }

    /**
     * Um item por lote em que a pessoa entrou, mais as mensagens de teste.
     *
     * @return list<array<string, mixed>>
     */
    private function itensDeEnvio(Contact $contact): array
    {
        $itens = [];

        $destinatarios = MessageBatchRecipient::query()
            ->with('batch')
            ->where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->get();

        foreach ($destinatarios as $destinatario) {
            $avisos = [];

            if ($destinatario->sent_at !== null) {
                $avisos[] = 'A mensagem deste lote já foi enviada em '.$destinatario->sent_at->format('d/m/Y H:i')
                    .'. Limpar aqui apaga o registro do envio, não a mensagem que chegou ao aparelho da pessoa.';
            }

            $itens[] = [
                'chave' => 'envios:'.$destinatario->id,
                'target' => CleanupTarget::Envios,
                'record_table' => 'message_batch_recipients',
                'record_id' => $destinatario->id,
                'lote_id' => $destinatario->message_batch_id,
                'nome' => 'Lote "'.($destinatario->batch?->name ?? 'sem nome').'"',
                'detalhe' => 'situação '.($destinatario->processing_status?->value ?? 'desconhecida'),
                'quantidade' => 1,
                'avisos' => $avisos,
                'envolve_sorteio' => false,
                'soft' => ['message_batch_recipients' => [$destinatario->id]],
                'pivot_tags' => [],
                'import_rows' => [],
            ];
        }

        $testes = WhatsAppTestMessage::query()->where('contact_id', $contact->id)->pluck('id')->all();

        if ($testes !== []) {
            $itens[] = [
                'chave' => 'envios:testes',
                'target' => CleanupTarget::Envios,
                'record_table' => 'whatsapp_test_messages',
                'record_id' => null,
                'nome' => 'Mensagens de teste',
                'detalhe' => count($testes).' '.(count($testes) === 1 ? 'envio de teste' : 'envios de teste').' para este número',
                'quantidade' => count($testes),
                'avisos' => [],
                'envolve_sorteio' => false,
                'soft' => ['whatsapp_test_messages' => $testes],
                'pivot_tags' => [],
                'import_rows' => [],
            ];
        }

        return $itens;
    }

    /**
     * Etiquetas, histórico e vínculo com a planilha importada.
     *
     * Os dois primeiros saem por exclusão suave. O terceiro não: a linha da
     * planilha continua existindo — ela e o registro do arquivo que alguém
     * enviou, e apagá-la faria a importação passar a mentir sobre quantas
     * linhas tinha. O que sai ali e só o vínculo com esta pessoa.
     *
     * @return list<array<string, mixed>>
     */
    private function itensDeCadastro(Contact $contact): array
    {
        $itens = [];

        $etiquetas = $contact->tags()->get();

        if ($etiquetas->isNotEmpty()) {
            $itens[] = [
                'chave' => 'cadastro:etiquetas',
                'target' => CleanupTarget::Cadastro,
                'record_table' => 'contact_tag',
                'record_id' => null,
                'nome' => 'Etiquetas aplicadas',
                'detalhe' => $etiquetas->pluck('name')->implode(', '),
                'quantidade' => $etiquetas->count(),
                'avisos' => [],
                'envolve_sorteio' => false,
                'soft' => [],
                'pivot_tags' => $etiquetas->map(fn ($etiqueta): array => [
                    'tag_id' => (int) $etiqueta->id,
                    'created_by' => $etiqueta->pivot->created_by ?? null,
                ])->all(),
                'import_rows' => [],
            ];
        }

        $historico = ContactHistory::query()->where('contact_id', $contact->id)->pluck('id')->all();

        if ($historico !== []) {
            $itens[] = [
                'chave' => 'cadastro:historico',
                'target' => CleanupTarget::Cadastro,
                'record_table' => 'contact_history',
                'record_id' => null,
                'nome' => 'Histórico do contato',
                'detalhe' => count($historico).' '.(count($historico) === 1 ? 'registro' : 'registros').' de alteração',
                'quantidade' => count($historico),
                'avisos' => ['O histórico é o que explica quem mudou o quê neste cadastro. Sem ele, alterações passadas ficam sem autor.'],
                'envolve_sorteio' => false,
                'soft' => ['contact_history' => $historico],
                'pivot_tags' => [],
                'import_rows' => [],
            ];
        }

        $linhas = ContactImportRow::query()->where('contact_id', $contact->id)->pluck('id')->all();

        if ($linhas !== []) {
            $itens[] = [
                'chave' => 'cadastro:importacao',
                'target' => CleanupTarget::Cadastro,
                'record_table' => 'contact_import_rows',
                'record_id' => null,
                'nome' => 'Vínculo com a importação',
                'detalhe' => count($linhas).' '.(count($linhas) === 1 ? 'linha de planilha aponta' : 'linhas de planilha apontam').' para este contato',
                'quantidade' => count($linhas),
                'avisos' => ['A linha da planilha não é apagada: sai apenas o vínculo dela com esta pessoa.'],
                'envolve_sorteio' => false,
                'soft' => [],
                'pivot_tags' => [],
                'import_rows' => $linhas,
            ];
        }

        return $itens;
    }

    /**
     * Recusa limpar conversa sem limpar a inscrição que nasceu dela.
     *
     * No expurgo, apagar a mensagem de origem apaga a inscrição por cascata do
     * banco. Deixar passar aqui significaria remover, semanas depois e em
     * silêncio, uma participação que o operador tinha escolhido manter.
     *
     * @param  Collection<string, array<string, mixed>>  $inventario
     * @param  list<string>  $escolhidas
     *
     * @throws ValidationException
     */
    private function recusarConversaQueLevaInscricao($inventario, array $escolhidas): void
    {
        $mensagensLimpas = [];

        foreach ($escolhidas as $chave) {
            $mensagensLimpas = [...$mensagensLimpas, ...($inventario[$chave]['mensagens'] ?? [])];
        }

        if ($mensagensLimpas === []) {
            return;
        }

        $orfas = KeywordCampaignParticipation::query()
            ->with('campaign')
            ->whereIn('conversation_message_id', $mensagensLimpas)
            ->get()
            ->reject(fn (KeywordCampaignParticipation $inscricao): bool => in_array('campanhas:'.$inscricao->id, $escolhidas, true));

        if ($orfas->isEmpty()) {
            return;
        }

        $nomes = $orfas->map(fn (KeywordCampaignParticipation $inscricao): string => '"'.($inscricao->campaign?->name ?? 'sem nome').'"')->unique()->implode(', ');

        throw ValidationException::withMessages([
            'itens' => 'As conversas escolhidas originaram inscrições em '.$nomes.'. A inscrição é projeção da mensagem que a criou, '
                .'e apagar a conversa levaria a inscrição junto no expurgo. Marque também essas inscrições, ou tire a conversa da seleção.',
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function mandarParaLixeira(string $tabela, array $ids): void
    {
        if ($ids === [] || ! isset(self::MODELOS[$tabela])) {
            return;
        }

        self::MODELOS[$tabela]::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @param  list<int>  $ids
     */
    private function tirarDaLixeira(string $tabela, array $ids): void
    {
        if ($ids === [] || ! isset(self::MODELOS[$tabela])) {
            return;
        }

        self::MODELOS[$tabela]::withTrashed()->whereIn('id', $ids)->restore();
    }

    /**
     * @param  list<int|null>  $loteIds
     */
    private function ressincronizarLotes(array $loteIds): void
    {
        $ids = array_values(array_unique(array_filter($loteIds)));

        if ($ids === []) {
            return;
        }

        MessageBatch::query()->whereIn('id', $ids)->each(function (MessageBatch $lote): void {
            $this->progress->sync($lote);
        });
    }
}
