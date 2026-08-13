<?php

namespace App\Services\InboundAttendance;

use App\Contracts\PairsBySession;
use App\Enums\ContactStatus;
use App\Enums\InboundAttendanceOutcome;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\InboundAttendanceAttempt;
use App\Models\InboundAttendanceProfile;
use App\Models\WhatsAppConnection;
use App\Services\Conversations\InternalNumbers;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Support\Carbon;

/**
 * Porta única do atendimento de entrada.
 *
 * Cada recusa devolve um código, e o código vira uma frase na fila. Isso é o
 * que separa "a conversa está parada" de "a conversa está parada porque o teto
 * de hoje acabou": a primeira não diz o que fazer, a segunda diz.
 */
class InboundAttendanceGuard
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Chave geral do atendimento de entrada.
     *
     * Separada de `conversation_automation.enabled` de propósito: desligar o
     * atendimento a quem escreve primeiro não pode exigir desligar junto a
     * pesquisa que já está rodando nos lotes.
     */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('inbound_attendance.enabled', '0');
    }

    /**
     * Pode abrir atendimento automático nesta conversa, com este perfil?
     *
     * @param  bool  $manual  Quem clicou passa por menos travas: o teto diário e
     *                        a homologação existem para conter o que sai sozinho,
     *                        e barrar uma pessoa que está olhando a conversa e
     *                        decidiu iniciá-la seria inverter o propósito.
     * @return array{allowed: bool, reason: ?string}
     */
    public function canStart(Conversation $conversation, ?InboundAttendanceProfile $profile, bool $manual = false, ?ConversationMessage $message = null): array
    {
        if (! $this->enabled()) {
            return $this->deny('atendimento_desligado');
        }

        /*
         | Não se responde a mensagem de semanas atrás.
         |
         | A fila guarda conversa parada, e parada há muito tempo é o caso comum
         | nela. Abrir atendimento a partir dali manda uma abertura de pesquisa
         | como resposta a uma frase que a pessoa já esqueceu.
         |
         | Aconteceu: a conversa 321 foi iniciada em 12/08 respondendo a um
         | "Certo, obrigada" de **15/07** — vinte e oito dias antes. Do lado
         | dela, uma conversa encerrada em julho voltou sozinha em agosto.
         |
         | Vale também no clique. Quem clica vê a conversa, mas a lista mostra a
         | data em letra pequena ao lado de vinte linhas, e "marcar todas" não
         | olha data nenhuma.
         */
        if ($message && $this->tooOld($message)) {
            return $this->deny('mensagem_antiga');
        }

        /*
         | O motor da pesquisa precisa estar ligado para a conversa continuar.
         |
         | A abertura sai pelo piso — quem escreveu merece resposta —, mas o que
         | vem depois dela não: a resposta da pessoa passa por `canEvaluate` e
         | por `canSend`, e as duas recusam com a automação desligada. Abrir
         | assim entregaria uma apresentação de pesquisa e depois silêncio, que
         | é pior que não ter aberto. O motivo aparece na fila, e a fila é onde
         | se descobre qual chave está faltando.
         */
        if (! $this->settings->get('conversation_automation.enabled', '0')) {
            return $this->deny('automacao_desligada');
        }

        if (! $this->settings->get('conversation_automation.auto_send_enabled', '0')) {
            return $this->deny('envio_automatico_desligado');
        }

        if (! $profile) {
            return $this->deny('sem_perfil');
        }

        if (! $profile->isRunnable()) {
            return $this->deny('perfil_inativo');
        }

        if (! $profile->conversation_flow_id) {
            return $this->deny('perfil_sem_fluxo');
        }

        // A conversa já está em uma pesquisa: quem responde é o motor de fluxo,
        // e abrir de novo mandaria uma segunda apresentação para a mesma pessoa.
        if (ConversationFlowState::query()->where('conversation_id', $conversation->id)->exists()) {
            return $this->deny('conversa_ja_tem_fluxo');
        }

        // Vale inclusive no clique: convidar a própria candidata a responder a
        // pesquisa dela não é uma decisão que alguém queira ter tomado por
        // engano ao marcar tudo na fila.
        if (app(InternalNumbers::class)->coversConversation($conversation)) {
            return $this->deny('numero_interno');
        }

        /*
         | Sem sessão conectada não se tenta.
         |
         | A lição é da rede de segurança, e custou 1535 linhas de repetição em
         | duas conversas: enquanto a sessão está fora do ar o envio falha com
         | certeza, e cada tentativa deixa uma linha na conversa. Voltando a
         | conexão, a mensagem seguinte tenta de novo — a pessoa está
         | inalcançável de qualquer jeito.
         */
        if (! $this->providerCanSend()) {
            return $this->deny('sem_conexao');
        }

        $contact = $conversation->contact;

        if ($contact && ! $this->contactEligible($contact)) {
            return $this->deny($contact->do_not_contact ? 'contato_nao_contatar' : 'contato_inativo');
        }

        if (! $manual && $profile->needsHumanApproval()) {
            return $this->deny('aguardando_homologacao');
        }

        if (! $manual && ! $this->withinWindow($profile)) {
            return $this->deny('fora_da_janela_de_horario');
        }

        if (! $manual && $this->profileCapReached($profile)) {
            return $this->deny('teto_diario_do_perfil');
        }

        if (! $manual && $this->globalCapReached()) {
            return $this->deny('teto_diario_global');
        }

        return $this->allow();
    }

    /**
     * Janela do perfil, com a janela global da automação como padrão.
     *
     * O perfil de fora do horário comercial existe justamente para dizer outra
     * coisa fora do horário comercial: por isso a janela é dele, e não uma só
     * para o sistema inteiro.
     */
    public function withinWindow(InboundAttendanceProfile $profile, ?Carbon $now = null): bool
    {
        $start = (string) ($profile->window_start ?: $this->settings->get('conversation_automation.window_start', '08:00'));
        $end = (string) ($profile->window_end ?: $this->settings->get('conversation_automation.window_end', '20:00'));

        if ($start === '' || $end === '' || $start === $end) {
            return true;
        }

        $current = ($now ?? now())->format('H:i');

        if ($start < $end) {
            return $current >= $start && $current <= $end;
        }

        // Janela que cruza a meia-noite (ex.: 20:00 às 08:00).
        return $current >= $start || $current <= $end;
    }

    /**
     * A mensagem é velha demais para merecer uma abertura?
     *
     * Zero desliga a trava, para quem quiser reprocessar histórico de propósito.
     */
    public function tooOld(ConversationMessage $message): bool
    {
        $horas = (int) $this->settings->get('inbound_attendance.max_message_age_hours', 72);

        if ($horas <= 0) {
            return false;
        }

        $quando = $message->received_at ?? $message->created_at;

        return $quando !== null && $quando->lessThan(now()->subHours($horas));
    }

    public function profileCapReached(InboundAttendanceProfile $profile): bool
    {
        $cap = (int) $profile->daily_start_limit;

        if ($cap <= 0) {
            return false;
        }

        return $this->startedToday($profile->id) >= $cap;
    }

    public function globalCapReached(): bool
    {
        $cap = (int) $this->settings->get('inbound_attendance.daily_start_limit', 200);

        if ($cap <= 0) {
            return false;
        }

        return $this->startedToday() >= $cap;
    }

    /**
     * Conversas abertas hoje pelo automático.
     *
     * O que uma pessoa iniciou à mão não conta: o teto existe para conter
     * enxurrada e laço de repetição, e nem um nem outro passa por um clique.
     */
    public function startedToday(?int $profileId = null): int
    {
        return InboundAttendanceAttempt::query()
            ->where('outcome', InboundAttendanceOutcome::Started)
            ->whereNull('started_by')
            ->when($profileId, fn ($query) => $query->where('inbound_attendance_profile_id', $profileId))
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    public function contactEligible(Contact $contact): bool
    {
        return ! $contact->do_not_contact
            && $contact->status === ContactStatus::Active
            && filled($contact->phone_normalized);
    }

    private function providerCanSend(): bool
    {
        if (! app(WhatsAppProviderManager::class)->provider() instanceof PairsBySession) {
            return true;
        }

        $connection = WhatsAppConnection::query()->latest('id')->first();

        // Só barra quando se sabe que a sessão caiu. Sem registro nenhum não dá
        // para afirmar nada, e presumir queda emudeceria o atendimento numa
        // instalação nova.
        return $connection === null || $connection->status === \App\Enums\WhatsAppConnectionStatus::Connected;
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => null];
    }

    /** @return array{allowed: bool, reason: ?string} */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
