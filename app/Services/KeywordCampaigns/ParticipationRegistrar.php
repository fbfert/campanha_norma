<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\ConsentStatus;
use App\Enums\ContactHistoryAction;
use App\Enums\ContactMatchStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\KeywordEnrollmentOutcome;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Models\Contact;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Models\KeywordCampaignParticipation;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Services\Contacts\ContactHistoryService;
use App\Services\IncomingMessages\ContactMatcherService;
use App\Services\SystemSettingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transforma um casamento de palavra-chave numa inscrição.
 *
 * Cria o contato quando o número é desconhecido, reaproveita quando não é, e
 * grava sempre a mensagem que originou a inscrição — é a prova de origem, e é
 * o que permite reconstruir a lista inteira quando um job morre no meio de uma
 * divulgação.
 */
class ParticipationRegistrar
{
    public function __construct(
        private readonly ContactMatcherService $matcher,
        private readonly ContactHistoryService $history,
        private readonly AuditLogger $audit,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Registra a inscrição, ou explica por que não registrou.
     */
    public function registrar(
        KeywordCampaign $campaign,
        ConversationMessage $message,
        string $matchedKeyword,
    ): EnrollmentResult {
        if ($campaign->estaCongelada()) {
            return EnrollmentResult::de(KeywordEnrollmentOutcome::ListaCongelada);
        }

        if (! $campaign->estaVigente()) {
            return EnrollmentResult::de(KeywordEnrollmentOutcome::ForaDeVigencia);
        }

        $phone = (string) $message->sender_phone_snapshot;
        $match = $this->matcher->match($phone);

        if ($match['status'] === ContactMatchStatus::InvalidPhone) {
            return EnrollmentResult::de(KeywordEnrollmentOutcome::TelefoneInvalido);
        }

        /*
         | O limite é conferido antes de criar contato, e não depois.
         |
         | Criar o contato e só então descobrir que não havia vaga deixaria na
         | base uma pessoa com consentimento gravado para uma campanha em que
         | ela não está inscrita — o pior dos dois mundos.
         */
        if ($campaign->atingiuLimite()) {
            return EnrollmentResult::de(KeywordEnrollmentOutcome::LimiteAtingido);
        }

        $emRevisao = $match['status'] === ContactMatchStatus::MultipleMatches;

        if ($emRevisao) {
            /*
             | Telefone que casa com mais de um contato ativo.
             |
             | Escolher um dos dois no automático inscreveria uma pessoa e
             | deixaria outra de fora sem que ninguém soubesse. A inscrição fica
             | pendurada no primeiro contato encontrado só para existir uma
             | linha na fila de revisão, e não conta como válida até um humano
             | resolver.
             */
            $contact = $match['matches']->whereNull('deleted_at')->first();

            if (! $contact instanceof Contact) {
                return EnrollmentResult::de(KeywordEnrollmentOutcome::TelefoneInvalido);
            }
        } elseif ($match['status'] === ContactMatchStatus::NotFound) {
            $contact = $this->criarContato($campaign, $message, (string) $match['phone']);
        } else {
            $contact = $match['contact'];
        }

        $this->ligarContatoAConversa($message, $contact);

        return $this->gravarParticipacao($campaign, $message, $contact, $matchedKeyword, $emRevisao);
    }

    /**
     * A conversa passa a apontar para o contato.
     *
     * Sem isto, número desconhecido virava contato e participação e **não
     * recebia a confirmação**: `ConversationReplyService` recusa criar mensagem
     * de saída em conversa sem contato, e a recusa é silenciosa. O caminho
     * principal desta etapa — quem ainda não está na base — ficava inscrito e
     * sem resposta nenhuma.
     *
     * `ProcessIncomingMessageJob` não tem como fazer isso: quando ele resolve a
     * conversa, o contato ainda não existe. É o mesmo que
     * `InboundAttendanceService` faz ao criar contato a partir de uma mensagem.
     */
    private function ligarContatoAConversa(ConversationMessage $message, Contact $contact): void
    {
        $conversation = $message->conversation;

        if (! $conversation || $conversation->contact_id !== null) {
            return;
        }

        $conversation->forceFill(['contact_id' => $contact->id])->save();
        $conversation->setRelation('contact', $contact);
        $message->setRelation('conversation', $conversation);

        // A mensagem recebida também fica ligada: sem isso ela continua
        // aparecendo como de contato não identificado no histórico.
        if ($message->contact_id === null) {
            $message->forceFill(['contact_id' => $contact->id])->save();
        }
    }

    /**
     * Contato novo, a partir de quem escreveu a palavra-chave.
     *
     * O consentimento é `granted` e a finalidade fica escrita em
     * `consent_text`. É diferente do que `InboundAttendanceService` faz, que
     * grava `not_informed` — e as duas estão certas: quem manda "bom dia" não
     * consentiu com nada, e quem escreve uma palavra que só existe no material
     * da campanha fez um ato inequívoco e específico.
     *
     * "Específico" é a palavra que importa: consentiu em participar da
     * campanha, não em receber disparo. É `ContactSelectionService` que aplica
     * essa distinção, tirando estes contatos da seleção padrão de lote.
     */
    private function criarContato(KeywordCampaign $campaign, ConversationMessage $message, string $phoneNormalized): Contact
    {
        $nome = $this->nomeCapturado($message) ?? 'Contato '.Str::substr($phoneNormalized, -4);

        $contact = Contact::create([
            'name' => $nome,
            'first_name' => Str::before($nome, ' '),
            'phone' => $message->sender_phone_snapshot,
            'phone_normalized' => $phoneNormalized,
            'status' => ContactStatus::Active,
            'source' => ContactSource::Gatilho,
            'consent_status' => ConsentStatus::Granted,
            'consent_source' => $message->isReaction() ? 'reacao_na_campanha' : 'gatilho_palavra_chave',
            'consent_text' => $this->textoDoConsentimento($campaign, $message),
            'consent_at' => ($message->received_at ?? now())->toDateString(),
            'country' => (string) $this->settings->get('contacts.default_country', 'BR'),
            'has_replied' => true,
            'first_replied_at' => $message->received_at ?? now(),
            'last_replied_at' => $message->received_at ?? now(),
        ]);

        $this->etiquetar($contact, $campaign);

        $this->history->record(
            $contact,
            ContactHistoryAction::Created,
            $message->isReaction()
                ? "Contato criado pela campanha \"{$campaign->name}\", a partir de reação na mensagem do convite."
                : "Contato criado pela campanha \"{$campaign->name}\", a partir de mensagem com palavra-chave.",
        );

        return $contact;
    }

    /**
     * A finalidade, escrita com o ato que a produziu.
     *
     * Escrever a palavra e reagir à mensagem são atos diferentes, e o registro
     * precisa dizer qual foi: seis meses depois, "consentiu" sozinho não
     * sustenta nada, e a diferença entre digitar uma palavra e tocar num emoji
     * é exatamente o que alguém vai querer conferir.
     */
    private function textoDoConsentimento(KeywordCampaign $campaign, ConversationMessage $message): string
    {
        $finalidade = 'A finalidade é a participação nessa campanha, e não o recebimento de disparo posterior.';

        if ($message->isReaction()) {
            $emoji = trim((string) $message->body);

            return "Reagiu com {$emoji} à mensagem que convidava para a campanha \"{$campaign->name}\" no WhatsApp. {$finalidade}";
        }

        return "Escreveu a palavra-chave da campanha \"{$campaign->name}\" no WhatsApp. {$finalidade}";
    }

    /**
     * Etiqueta da campanha, criada na primeira inscrição.
     *
     * Serve para achar essas pessoas depois sem depender de a origem continuar
     * significando a mesma coisa daqui a três campanhas.
     */
    private function etiquetar(Contact $contact, KeywordCampaign $campaign): void
    {
        $slug = Str::slug('campanha-'.$campaign->name.'-'.$campaign->id);

        $tag = Tag::withTrashed()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => "Campanha: {$campaign->name}",
                'description' => 'Etiqueta automática das inscrições por palavra-chave desta campanha.',
                'is_active' => true,
            ],
        );

        if ($tag->trashed()) {
            $tag->restore();
        }

        $contact->tags()->syncWithoutDetaching([$tag->id]);
    }

    private function gravarParticipacao(
        KeywordCampaign $campaign,
        ConversationMessage $message,
        Contact $contact,
        string $matchedKeyword,
        bool $emRevisao,
    ): EnrollmentResult {
        $nome = $this->nomeCapturado($message);

        $situacao = match (true) {
            $emRevisao => KeywordParticipationStatus::EmRevisao,
            $nome === null => KeywordParticipationStatus::SemNome,
            default => KeywordParticipationStatus::Valida,
        };

        try {
            $participacao = DB::transaction(fn (): KeywordCampaignParticipation => KeywordCampaignParticipation::create([
                'keyword_campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'conversation_message_id' => $message->id,
                'matched_keyword' => Str::limit($matchedKeyword, 120, ''),
                'captured_name' => $nome,
                'status' => $situacao,
                'eligibility' => KeywordParticipationEligibility::NaoVerificada,
            ]));
        } catch (QueryException $excecao) {
            /*
             | A chave única do banco é a trava de verdade.
             |
             | Verificar antes do insert não resolve: duas mensagens quase
             | simultâneas da mesma pessoa passam as duas pela verificação antes
             | de qualquer uma gravar. Aqui a corrida já aconteceu e o banco
             | decidiu — o que resta é reconhecer que a pessoa já está inscrita,
             | que não é erro nenhum.
             */
            if (! $this->violouUnicidade($excecao)) {
                throw $excecao;
            }

            $existente = KeywordCampaignParticipation::query()
                ->where('keyword_campaign_id', $campaign->id)
                ->where('contact_id', $contact->id)
                ->first();

            return EnrollmentResult::de(KeywordEnrollmentOutcome::JaInscrita, $existente);
        }

        $this->history->record(
            $contact,
            ContactHistoryAction::Updated,
            "Inscrição registrada na campanha \"{$campaign->name}\" pela palavra \"{$matchedKeyword}\".",
        );

        $this->audit->log(
            'keyword_campaign.participation_created',
            "Inscrição na campanha \"{$campaign->name}\".",
            $participacao,
            null,
            [
                'keyword_campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'conversation_message_id' => $message->id,
                'matched_keyword' => $matchedKeyword,
                'status' => $situacao->value,
            ],
        );

        return EnrollmentResult::de(
            $emRevisao ? KeywordEnrollmentOutcome::EmRevisao : KeywordEnrollmentOutcome::Registrada,
            $participacao,
        );
    }

    /**
     * O nome que o provedor informou, quando informou.
     *
     * Nunca sobrescreve o nome de um contato já cadastrado: o cadastro foi
     * escrito por nós e é mais confiável que um apelido que a pessoa escolheu e
     * troca quando quiser.
     */
    private function nomeCapturado(ConversationMessage $message): ?string
    {
        $nome = trim((string) $message->sender_name_snapshot);

        return $nome === '' ? null : Str::limit($nome, 120, '');
    }

    /**
     * A exceção é de chave duplicada?
     *
     * O código muda com o banco: 23000 é o SQLSTATE de violação de restrição de
     * integridade tanto no MySQL quanto no SQLite dos testes.
     */
    private function violouUnicidade(QueryException $excecao): bool
    {
        return $excecao->getCode() === '23000'
            || str_contains(Str::lower($excecao->getMessage()), 'unique');
    }
}
