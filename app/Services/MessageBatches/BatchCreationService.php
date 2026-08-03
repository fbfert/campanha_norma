<?php

namespace App\Services\MessageBatches;

use App\Enums\MessageBatchRecipientEligibility;
use App\Enums\MessageBatchSelectionType;
use App\Enums\MessageBatchStatus;
use App\Models\ConversationFlow;
use App\Models\MessageBatch;
use App\Models\MessageBatchEvent;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageTemplates\MessageTemplateService;
use App\Services\Placeholders\PlaceholderParserService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchCreationService
{
    public function __construct(
        private readonly ContactSelectionService $selection,
        private readonly ContactEligibilityService $eligibility,
        private readonly RandomSelectionService $random,
        private readonly PlaceholderParserService $parser,
        private readonly MessageTemplateService $templates,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, User $user): MessageBatch
    {
        $isCampaign = $this->isCampaign($data);
        $campaignTemplates = $isCampaign ? $this->campaignTemplates($data) : collect();
        $body = $isCampaign ? $this->campaignBody($campaignTemplates) : $this->body($data);
        $this->ensureBodiesAreValid($isCampaign ? $campaignTemplates->pluck('body')->all() : [$body]);
        $template = ! $isCampaign && filled($data['message_template_id'] ?? null) ? MessageTemplate::findOrFail((int) $data['message_template_id']) : null;
        $flow = $this->conversationFlow($data);
        $contacts = $this->selection->select($data);
        $seed = $data['random_seed'] ?? $this->random->seed();
        $positions = $this->random->positions($contacts->pluck('id')->all(), $seed);

        return DB::transaction(function () use ($data, $user, $body, $template, $flow, $contacts, $seed, $positions, $isCampaign, $campaignTemplates): MessageBatch {
            $batch = MessageBatch::create([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'is_campaign' => $isCampaign,
                'conversation_flow_id' => $flow?->id,
                'conversation_flow_snapshot' => $flow ? $this->flowSnapshot($flow) : null,
                'message_template_id' => $template?->id,
                'message_template_version' => $template?->version,
                'message_body_snapshot' => $body,
                'campaign_templates_snapshot' => $isCampaign ? $this->campaignSnapshot($campaignTemplates) : null,
                'placeholders_snapshot' => $this->placeholdersSnapshot($isCampaign ? $campaignTemplates->pluck('body')->all() : [$body]),
                'selection_type' => MessageBatchSelectionType::from($data['selection_type'] ?? 'manual'),
                'selection_filters' => $data['filters'] ?? [],
                'status' => MessageBatchStatus::Draft,
                'random_seed' => $seed,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->writeRecipients($batch, $contacts, $body, $positions, $isCampaign ? $campaignTemplates : null, $seed);
            $this->recount($batch);
            $this->event($batch, $user, 'created', 'Lote criado como rascunho.');
            if ($flow) {
                $this->event($batch, $user, 'conversation_flow_linked', 'Fluxo conversacional vinculado ao lote.', ['flow_id' => $flow->id, 'flow_name' => $flow->name]);
            }
            if ($isCampaign) {
                $this->event($batch, $user, 'campaign_templates_selected', 'Modelos da campanha selecionados.', ['template_ids' => $campaignTemplates->pluck('id')->values()->all()]);
            }
            $this->audit->log('message_batch.created', 'Lote de mensagens criado.', $batch, null, $batch->only(['name', 'status', 'selection_total', 'eligible_total']), $user);

            return $batch;
        });
    }

    public function update(MessageBatch $batch, array $data, User $user): MessageBatch
    {
        $this->ensureDraft($batch);

        $isCampaign = $this->isCampaign($data);
        $campaignTemplates = $isCampaign ? $this->campaignTemplates($data) : collect();
        $body = $isCampaign ? $this->campaignBody($campaignTemplates) : $this->body($data);
        $this->ensureBodiesAreValid($isCampaign ? $campaignTemplates->pluck('body')->all() : [$body]);

        $flow = $this->conversationFlow($data, $batch);

        return DB::transaction(function () use ($batch, $data, $user, $body, $flow, $isCampaign, $campaignTemplates): MessageBatch {
            $template = ! $isCampaign && filled($data['message_template_id'] ?? null) ? MessageTemplate::findOrFail((int) $data['message_template_id']) : null;
            $contacts = $this->selection->select($data);
            $seed = $data['random_seed'] ?? $batch->random_seed ?? $this->random->seed();
            $positions = $this->random->positions($contacts->pluck('id')->all(), $seed);

            $batch->recipients()->delete();
            $batch->fill([
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'is_campaign' => $isCampaign,
                'conversation_flow_id' => $flow?->id,
                'conversation_flow_snapshot' => $flow ? $this->flowSnapshot($flow) : null,
                'message_template_id' => $template?->id,
                'message_template_version' => $template?->version,
                'message_body_snapshot' => $body,
                'campaign_templates_snapshot' => $isCampaign ? $this->campaignSnapshot($campaignTemplates) : null,
                'placeholders_snapshot' => $this->placeholdersSnapshot($isCampaign ? $campaignTemplates->pluck('body')->all() : [$body]),
                'selection_type' => MessageBatchSelectionType::from($data['selection_type'] ?? 'manual'),
                'selection_filters' => $data['filters'] ?? [],
                'random_seed' => $seed,
                'updated_by' => $user->id,
            ])->save();

            $this->writeRecipients($batch, $contacts, $body, $positions, $isCampaign ? $campaignTemplates : null, $seed);
            $this->recount($batch);
            $this->event($batch, $user, 'updated', 'Lote atualizado.');
            if ($isCampaign) {
                $this->event($batch, $user, 'campaign_templates_selected', 'Modelos da campanha selecionados.', ['template_ids' => $campaignTemplates->pluck('id')->values()->all()]);
            }
            $this->audit->log('message_batch.updated', 'Lote de mensagens atualizado.', $batch, null, $batch->only(['name', 'selection_total', 'eligible_total']), $user);

            return $batch;
        });
    }

    /**
     * Reavalia os destinatários contra o cadastro atual do contato.
     *
     * Existe para o caminho mais comum de correção: o lote acusa "falta a
     * cidade", alguém completa o cadastro, e sem isto seria preciso refazer o
     * lote inteiro para o contato voltar a ser apto.
     *
     * Só mexe em quem ainda não teve envio. Destinatário enviado, na fila ou em
     * processamento fica intocado: reescrever a mensagem de quem já recebeu
     * apagaria o registro do que foi enviado de fato.
     *
     * @return array{avaliados: int, agora_aptos: int, agora_inaptos: int}
     */
    public function revalidate(MessageBatch $batch, User $user): array
    {
        if (! in_array($batch->status, [MessageBatchStatus::Draft, MessageBatchStatus::Ready], true)) {
            throw ValidationException::withMessages(['batch' => 'Só rascunho ou lote preparado pode ser atualizado.']);
        }

        $resultado = ['avaliados' => 0, 'agora_aptos' => 0, 'agora_inaptos' => 0];

        DB::transaction(function () use ($batch, &$resultado): void {
            foreach ($batch->recipients()->with('contact')->get() as $recipient) {
                if ($recipient->sent_at !== null || $recipient->external_message_id !== null) {
                    continue;
                }

                $contact = $recipient->contact;

                if (! $contact) {
                    continue;
                }

                $eraApto = $recipient->eligibility_status === MessageBatchRecipientEligibility::Eligible;
                $corpo = $recipient->message_template_name_snapshot !== null && $recipient->template
                    ? $recipient->template->body
                    : $batch->message_body_snapshot;

                $avaliacao = $this->eligibility->evaluate($contact, (string) $corpo);
                $resultado['avaliados']++;

                $recipient->forceFill([
                    'eligibility_status' => $avaliacao['eligible'] ? MessageBatchRecipientEligibility::Eligible : MessageBatchRecipientEligibility::Excluded,
                    'ineligibility_reason' => $avaliacao['reason'],
                    'rendered_message' => $avaliacao['eligible'] ? $avaliacao['rendered_message'] : null,
                    'render_errors' => $avaliacao['render_errors'],
                    // Os snapshots acompanham: o lote passa a valer com o dado
                    // que o contato tem agora, que e o motivo de atualizar.
                    'contact_name_snapshot' => $contact->name,
                    'contact_first_name_snapshot' => $contact->first_name,
                    'contact_phone_snapshot' => $contact->phone,
                    'contact_email_snapshot' => $contact->email,
                    'contact_city_snapshot' => $contact->city,
                    'contact_state_snapshot' => $contact->state,
                    'contact_country_snapshot' => $contact->country,
                ])->save();

                if ($avaliacao['eligible'] && ! $eraApto) {
                    $resultado['agora_aptos']++;
                }

                if (! $avaliacao['eligible'] && $eraApto) {
                    $resultado['agora_inaptos']++;
                }
            }

            $this->recount($batch);
        });

        $this->event($batch, $user, 'revalidated', 'Destinatários reavaliados com o cadastro atual.', $resultado);
        $this->audit->log('message_batch.revalidated', 'Lote atualizado com o cadastro atual dos contatos.', $batch, null, $resultado, $user);

        return $resultado;
    }

    public function randomize(MessageBatch $batch, User $user): void
    {
        $this->ensureDraft($batch);
        $seed = $this->random->seed();
        $positions = $this->random->positions($batch->recipients()->pluck('contact_id')->all(), $seed);

        foreach ($batch->recipients as $recipient) {
            $recipient->forceFill(['random_position' => ($positions[$recipient->contact_id] ?? 0) + 1])->save();
        }

        $batch->forceFill(['random_seed' => $seed, 'updated_by' => $user->id])->save();
        $this->event($batch, $user, 'random_order_generated', 'Ordem aleatória gerada.', ['seed' => $seed]);
        $this->audit->log('message_batch.randomized', 'Ordem aleatória do lote gerada.', $batch, null, ['seed' => $seed], $user);
    }

    public function prepare(MessageBatch $batch, User $user, string $confirmation): void
    {
        $this->ensureDraft($batch);
        if ($confirmation !== 'Confirmo a criação deste lote com os destinatários e mensagens apresentados.') {
            throw ValidationException::withMessages(['confirmation' => 'Confirmação explícita invalida.']);
        }
        if ($batch->eligible_total < 1) {
            throw ValidationException::withMessages(['batch' => 'O lote precisa ter pelo menos um destinatário apto.']);
        }

        $batch->forceFill(['status' => MessageBatchStatus::Ready, 'prepared_at' => now(), 'updated_by' => $user->id])->save();
        $this->event($batch, $user, 'marked_ready', 'Lote marcado como preparado.');
        $this->audit->log('message_batch.marked_ready', 'Lote marcado como preparado.', $batch, null, ['eligible_total' => $batch->eligible_total], $user);
    }

    public function duplicate(MessageBatch $batch, User $user): MessageBatch
    {
        $copy = MessageBatch::create([
            'name' => $batch->name.' - copia',
            'description' => $batch->description,
            'is_campaign' => $batch->is_campaign,
            'conversation_flow_id' => $batch->conversation_flow_id,
            'conversation_flow_snapshot' => $batch->conversation_flow_snapshot,
            'message_template_id' => $batch->message_template_id,
            'message_template_version' => $batch->message_template_version,
            'message_body_snapshot' => $batch->message_body_snapshot,
            'campaign_templates_snapshot' => $batch->campaign_templates_snapshot,
            'placeholders_snapshot' => $batch->placeholders_snapshot,
            'selection_type' => $batch->selection_type,
            'selection_filters' => $batch->selection_filters,
            'status' => MessageBatchStatus::Draft,
            'random_seed' => $this->random->seed(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->event($copy, $user, 'duplicated', 'Lote duplicado.', ['source_id' => $batch->id]);
        $this->audit->log('message_batch.duplicated', 'Lote de mensagens duplicado.', $copy, null, ['source_id' => $batch->id], $user);

        return $copy;
    }

    public function cancel(MessageBatch $batch, User $user, string $reason): void
    {
        if (! in_array($batch->status, [MessageBatchStatus::Draft, MessageBatchStatus::Ready], true)) {
            throw ValidationException::withMessages(['batch' => 'Este lote não pode ser cancelado.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['cancel_reason' => 'Informe o motivo do cancelamento.']);
        }
        $batch->forceFill(['status' => MessageBatchStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason])->save();
        $this->event($batch, $user, 'cancelled', 'Lote cancelado.', ['reason' => $reason]);
        $this->audit->log('message_batch.cancelled', 'Lote de mensagens cancelado.', $batch, null, ['reason' => $reason], $user);
    }

    private function writeRecipients(MessageBatch $batch, $contacts, string $body, array $positions, ?Collection $campaignTemplates = null, ?string $seed = null): void
    {
        foreach ($contacts as $contact) {
            $template = $campaignTemplates?->isNotEmpty() ? $this->templateForContact($campaignTemplates, $seed ?? $batch->random_seed ?? '', (int) $contact->id) : null;
            $recipientBody = $template?->body ?? $body;
            $result = $this->eligibility->evaluate($contact, $recipientBody);
            $batch->recipients()->create([
                'contact_id' => $contact->id,
                'message_template_id' => $template?->id,
                'message_template_version' => $template?->version,
                'message_template_name_snapshot' => $template?->name,
                'random_position' => ($positions[$contact->id] ?? 0) + 1,
                'eligibility_status' => $result['eligible'] ? MessageBatchRecipientEligibility::Eligible : MessageBatchRecipientEligibility::Excluded,
                'ineligibility_reason' => $result['reason'],
                'contact_name_snapshot' => $contact->name,
                'contact_first_name_snapshot' => $contact->first_name,
                'contact_phone_snapshot' => $contact->phone,
                'contact_email_snapshot' => $contact->email,
                'contact_city_snapshot' => $contact->city,
                'contact_state_snapshot' => $contact->state,
                'contact_country_snapshot' => $contact->country,
                'rendered_message' => $result['eligible'] ? $result['rendered_message'] : null,
                'render_errors' => $result['render_errors'],
            ]);
        }
    }

    private function recount(MessageBatch $batch): void
    {
        $batch->forceFill([
            'selection_total' => $batch->recipients()->count(),
            'eligible_total' => $batch->recipients()->where('eligibility_status', 'eligible')->count(),
            'ineligible_total' => $batch->recipients()->where('eligibility_status', '!=', 'eligible')->count(),
        ])->save();
    }

    private function body(array $data): string
    {
        if (filled($data['message_template_id'] ?? null) && blank($data['message_body'] ?? null)) {
            return MessageTemplate::findOrFail((int) $data['message_template_id'])->body;
        }

        return (string) ($data['message_body'] ?? '');
    }

    private function isCampaign(array $data): bool
    {
        return filter_var($data['is_campaign'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function campaignTemplates(array $data): Collection
    {
        $ids = collect($data['message_template_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['message_template_ids' => 'Selecione pelo menos um modelo para a campanha.']);
        }

        if ($ids->count() > 10) {
            throw ValidationException::withMessages(['message_template_ids' => 'A campanha pode usar no máximo 10 modelos.']);
        }

        $templates = MessageTemplate::query()
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (MessageTemplate $template) => $ids->search($template->id))
            ->values();

        if ($templates->count() !== $ids->count()) {
            throw ValidationException::withMessages(['message_template_ids' => 'Todos os modelos da campanha precisam estar ativos.']);
        }

        return $templates;
    }

    /**
     * Fluxo conversacional que o lote ativa após cada envio bem-sucedido.
     * Só fluxo ativo pode ser vinculado; um fluxo já vinculado que foi pausado
     * depois continua aceito, para que pausar o fluxo não trave a edição do lote.
     */
    private function conversationFlow(array $data, ?MessageBatch $batch = null): ?ConversationFlow
    {
        $id = (int) ($data['conversation_flow_id'] ?? 0);

        if ($id < 1) {
            return null;
        }

        $flow = ConversationFlow::find($id);

        if (! $flow) {
            throw ValidationException::withMessages(['conversation_flow_id' => 'Fluxo conversacional não encontrado.']);
        }

        if (! $flow->isRunnable() && $batch?->conversation_flow_id !== $flow->id) {
            throw ValidationException::withMessages(['conversation_flow_id' => 'O fluxo conversacional precisa estar ativo para ser vinculado a um lote.']);
        }

        return $flow;
    }

    private function flowSnapshot(ConversationFlow $flow): array
    {
        return [
            'id' => $flow->id,
            'name' => $flow->name,
            'status' => $flow->status->value,
            'validity_hours' => $flow->validity_hours,
            'max_main_questions' => $flow->max_main_questions,
            'transparency_enabled' => (bool) $flow->transparency_enabled,
        ];
    }

    private function campaignBody(Collection $templates): string
    {
        return 'CAMPANHA: modelos sorteados por destinatário - '.$templates->pluck('name')->join(', ');
    }

    private function campaignSnapshot(Collection $templates): array
    {
        return $templates->map(fn (MessageTemplate $template): array => [
            'id' => $template->id,
            'name' => $template->name,
            'version' => $template->version,
            'body' => $template->body,
            'placeholders' => $this->parser->parse($template->body)['valid'],
        ])->values()->all();
    }

    private function placeholdersSnapshot(array $bodies): array
    {
        return collect($bodies)
            ->flatMap(fn (string $body) => $this->parser->parse($body)['valid'])
            ->unique()
            ->values()
            ->all();
    }

    private function ensureBodiesAreValid(array $bodies): void
    {
        foreach ($bodies as $body) {
            $this->templates->ensureValidBody($body);
        }
    }

    private function templateForContact(Collection $templates, string $seed, int $contactId): MessageTemplate
    {
        $index = hexdec(substr(hash('sha256', $seed.'|template|'.$contactId), 0, 8)) % $templates->count();

        return $templates->values()->get($index);
    }

    private function ensureDraft(MessageBatch $batch): void
    {
        if ($batch->status !== MessageBatchStatus::Draft) {
            throw ValidationException::withMessages(['batch' => 'Lotes preparados ou cancelados não podem ser alterados diretamente.']);
        }
    }

    private function event(MessageBatch $batch, User $user, string $type, string $description, ?array $metadata = null): MessageBatchEvent
    {
        return MessageBatchEvent::create([
            'message_batch_id' => $batch->id,
            'user_id' => $user->id,
            'event_type' => $type,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
