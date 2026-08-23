<?php

namespace App\Console\Commands;

use App\Enums\KeywordEnrollmentOutcome;
use App\Models\ConversationEvent;
use App\Models\KeywordCampaignParticipation;
use App\Services\KeywordCampaigns\CampaignReplyService;
use App\Services\KeywordCampaigns\EnrollmentResult;
use Illuminate\Console\Command;

/**
 * A confirmação que nunca saiu.
 *
 * `campanhas:reprocessar` recupera a inscrição, e de propósito não responde a
 * ninguém: ele varre histórico, e histórico varrido em massa não deve virar
 * mensagem em massa. Mas quem se inscreveu durante uma queda fica num estado
 * esquisito — está na lista, concorre ao sorteio, e não sabe de nada.
 *
 * Este comando fecha essa lacuna, e só ela. Manda o texto oficial da campanha
 * para inscrição válida que não tem resposta registrada, uma vez.
 *
 * ## Por que ele é chato de rodar
 *
 * Envio não volta atrás. Por isso o padrão é execução seca: sem `--enviar` ele
 * mostra quem receberia e não manda nada. E, mesmo com `--enviar`, ele para e
 * pergunta antes, porque a diferença entre reenviar para uma pessoa e para
 * quatrocentas é uma opção de linha de comando digitada com pressa.
 */
class CampanhasReenviarConfirmacaoCommand extends Command
{
    protected $signature = 'campanhas:reenviar-confirmacao' // ortografia:ignorar - nome de comando, digitado no terminal e por isso sem acento
        .' {--campanha= : Apenas as inscrições desta campanha}'
        .' {--participacao= : Apenas esta inscrição}' // ortografia:ignorar - nome de opção, digitado no terminal e por isso sem acento
        .' {--enviar : Envia de verdade. Sem esta opção, nada sai}';

    protected $description = 'Envia a confirmação para inscrição que ficou sem resposta.';

    public function handle(CampaignReplyService $replies): int
    {
        $enviar = (bool) $this->option('enviar');

        $inscricoes = KeywordCampaignParticipation::query()
            ->with(['campaign', 'contact', 'message'])
            ->when($this->option('campanha'), fn ($q) => $q->where('keyword_campaign_id', (int) $this->option('campanha')))
            ->when($this->option('participacao'), fn ($q) => $q->whereKey((int) $this->option('participacao')))
            ->orderBy('id')
            ->get()
            ->filter(fn (KeywordCampaignParticipation $i): bool => $this->ficouSemResposta($i));

        if ($inscricoes->isEmpty()) {
            $this->info('Nenhuma inscrição sem confirmação.');

            return self::SUCCESS;
        }

        $this->table(
            ['Inscrição', 'Campanha', 'Contato', 'Telefone', 'Inscrita em'],
            $inscricoes->map(fn (KeywordCampaignParticipation $i): array => [
                $i->id,
                $i->campaign?->name ?? '-',
                $i->contact?->name ?? '-',
                $i->contact?->phone_normalized ?? '-',
                (string) $i->created_at,
            ])->all(),
        );

        if (! $enviar) {
            $this->comment($inscricoes->count().' '.($inscricoes->count() === 1 ? 'pessoa receberia' : 'pessoas receberiam')
                .' a confirmação. Nada foi enviado: use --enviar para valer.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Enviar a confirmação para '.$inscricoes->count().' '
            .($inscricoes->count() === 1 ? 'pessoa' : 'pessoas').'? Mensagem enviada não volta atrás.')) {
            $this->warn('Cancelado. Nada foi enviado.');

            return self::SUCCESS;
        }

        $enviadas = 0;
        $mudas = 0;

        foreach ($inscricoes as $inscricao) {
            $campanha = $inscricao->campaign;
            $recebida = $inscricao->message;

            if ($campanha === null || $recebida === null) {
                $mudas++;

                continue;
            }

            $mensagem = $replies->responder(
                $campanha,
                $recebida,
                EnrollmentResult::de(KeywordEnrollmentOutcome::Registrada, $inscricao),
            );

            $mensagem === null ? $mudas++ : $enviadas++;
        }

        $this->info("{$enviadas} ".($enviadas === 1 ? 'confirmação enfileirada' : 'confirmações enfileiradas')
            .($mudas > 0 ? ", {$mudas} sem para onde responder." : '.'));

        return self::SUCCESS;
    }

    /**
     * Nenhuma resposta de campanha foi registrada para esta inscrição.
     *
     * O evento é gravado por `CampaignReplyService` no mesmo instante em que a
     * resposta é enfileirada, então ele é o registro certo para consultar: diz
     * que a pessoa já foi respondida mesmo que o envio em si tenha falhado
     * depois. Reenviar por causa de falha de entrega é outro assunto, e tem o
     * caminho de tentativa do próprio envio.
     */
    private function ficouSemResposta(KeywordCampaignParticipation $inscricao): bool
    {
        return ! ConversationEvent::query()
            ->where('event_type', 'keyword_campaign_reply_queued')
            ->where('metadata->participation_id', $inscricao->id)
            ->exists();
    }
}
