<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\ConversationMessageDirection;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Services\Conversations\ConversationEventService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * O gatilho de campanha, no caminho da mensagem recebida.
 *
 * Roda dentro de `EvaluateConversationFlowJob`, antes da decisão de roteamento
 * e sob a trava por conversa que aquele job já segura. Não lê nem escreve
 * estágio de fluxo, não move `last_processed_message_id` e não conhece
 * `ConversationFlowService`: a única coisa que toma emprestado é a trava, que é
 * o que impede duas mensagens seguidas da mesma pessoa abrirem duas inscrições
 * e criarem dois contatos para o mesmo número.
 */
class KeywordCampaignTrigger
{
    /**
     * Quanto tempo a lista de campanhas vigentes fica em cache.
     *
     * Este é o caminho quente da etapa: roda em toda mensagem recebida, e
     * enquanto não houver campanha cadastrada precisa custar praticamente nada.
     * O preço é uma janela de defasagem deste tamanho nas bordas da vigência —
     * uma campanha recém-ativada pode demorar até meio minuto para começar a
     * pegar. Gravar uma campanha limpa o cache, então na prática a defasagem só
     * aparece quando a borda é o relógio, e não uma edição.
     */
    private const CACHE_TTL_SECONDS = 30;

    private const CACHE_KEY = 'keyword_campaigns.avaliaveis';

    /**
     * A última avaliação consumiu a mensagem?
     *
     * Consumida quer dizer: era só a palavra-chave, virou inscrição, e não deve
     * ser lida por mais ninguém como se fosse conteúdo.
     */
    private bool $consumiuAMensagem = false;

    public function __construct(
        private readonly KeywordMatcherService $matcher,
        private readonly ParticipationRegistrar $registrar,
        private readonly CampaignReplyService $replies,
        private readonly ConversationEventService $events,
    ) {}

    /**
     * Avalia a mensagem contra as campanhas vigentes.
     *
     * Devolve `true` quando alguma campanha atendeu a mensagem — o que faz o
     * job pular a abertura do atendimento de entrada. Quem escreveu a
     * palavra-chave já disse o que queria, e a saudação genérica do atendimento
     * seria a segunda mensagem no mesmo minuto. Numa divulgação de centenas de
     * pessoas isso é o dobro do volume exatamente no pico, que é o
     * comportamento que mais rápido leva um número do WhatsApp Web a bloqueio.
     *
     * @return list<EnrollmentResult> os resultados, um por campanha que casou
     */
    public function avaliar(ConversationMessage $message): array
    {
        if (! $this->mensagemElegivel($message)) {
            return [];
        }

        $campanhas = $this->avaliaveis();

        if ($campanhas->isEmpty()) {
            return [];
        }

        $texto = $this->matcher->textoParaCasamento($message);
        $resultados = [];
        $this->consumiuAMensagem = false;

        foreach ($campanhas as $campanha) {
            $palavra = $this->matcher->match($texto, $campanha->keywordList());

            if ($palavra === null) {
                continue;
            }

            $resultado = $this->registrar->registrar($campanha, $message, $palavra);
            $resultados[] = $resultado;

            $this->registrarEvento($message, $campanha, $palavra, $resultado);
            $this->replies->responder($campanha, $message, $resultado);

            /*
             | Mensagem que é só a palavra-chave não chega ao motor da 9A.
             |
             | Em 17/08/2026, numa conversa com pesquisa em andamento, "batata"
             | foi gravada como resposta à pergunta sobre o problema mais
             | urgente da cidade — e o motor avançou para a pergunta seguinte
             | com esse dado dentro. A pessoa estava se inscrevendo num sorteio,
             | não opinando sobre nada.
             |
             | Só a palavra sozinha. "falta saúde no bairro", numa campanha cuja
             | palavra é `saude`, é a resposta da pessoa e precisa passar.
             */
            if ($this->matcher->mensagemEhSoAPalavra($texto, $campanha->keywordList())) {
                $this->consumiuAMensagem = true;
            }
        }

        return $resultados;
    }

    public function consumiuAMensagem(): bool
    {
        return $this->consumiuAMensagem;
    }

    /**
     * Alguma campanha assumiu esta mensagem?
     *
     * @param  list<EnrollmentResult>  $resultados
     */
    public function algumAtendeu(array $resultados): bool
    {
        foreach ($resultados as $resultado) {
            if ($resultado->atendeuAMensagem()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mensagem que o gatilho considera.
     *
     * Eco de mensagem nossa não inscreve ninguém, e mensagem sem texto escrito
     * também não: o casamento lê `body` de propósito, para que áudio transcrito
     * fique de fora. Mensagem de grupo não chega até aqui — o pipeline de
     * entrada a descarta antes de gravar.
     */
    private function mensagemElegivel(ConversationMessage $message): bool
    {
        return $message->direction === ConversationMessageDirection::Incoming
            && filled($this->matcher->textoParaCasamento($message));
    }

    /**
     * Campanhas que o gatilho testa, com cache curto.
     *
     * O cache guarda identificadores, e não modelos: quando não há campanha
     * nenhuma — que é o estado normal do sistema — o caminho inteiro custa uma
     * leitura de cache e nenhuma consulta ao banco.
     *
     * Quem decide se a campanha aceita alguém não é este método: é
     * `ParticipationRegistrar`, que reconfere a vigência no modelo carregado. É
     * essa separação que permite uma campanha ativa fora do período responder
     * que acabou em vez de ficar muda.
     *
     * @return Collection<int, KeywordCampaign>
     */
    private function avaliaveis(): Collection
    {
        $ids = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => KeywordCampaign::query()->avaliavel()->orderBy('id')->pluck('id')->all(),
        );

        if ($ids === []) {
            return collect();
        }

        // A situação é reconferida no banco: o cache pode estar meio minuto
        // atrasado, e ele só decide se vale consultar.
        return KeywordCampaign::query()
            ->avaliavel()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /**
     * O cache precisa cair quando alguém mexe numa campanha, senão ligar a
     * campanha na tela não liga a campanha de verdade por meio minuto.
     */
    public static function esquecerCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function registrarEvento(
        ConversationMessage $message,
        KeywordCampaign $campanha,
        string $palavra,
        EnrollmentResult $resultado,
    ): void {
        $conversation = $message->conversation;

        if ($conversation === null) {
            return;
        }

        $this->events->record(
            $conversation,
            'keyword_campaign_evaluated',
            "Campanha \"{$campanha->name}\": {$resultado->outcome->label()}.",
            $message,
            null,
            [
                'keyword_campaign_id' => $campanha->id,
                'matched_keyword' => $palavra,
                'outcome' => $resultado->outcome->value,
                'participation_id' => $resultado->participation?->id,
            ],
        );
    }
}
