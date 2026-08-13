<?php

namespace App\Services\Ai;

use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Enums\TranscriptionStatus;
use App\Data\Ai\AiCompletionRequest;
use App\Models\ConversationMessage;
use App\Models\MessageTranscription;
use App\Services\Conversations\ConversationMediaService;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lê a imagem que a pessoa mandou e escreve o que há nela.
 *
 * Antes disto, foto e figurinha eram silêncio: o motor só avalia texto, a
 * transcrição só trata áudio, e o que sobrava era um pedido para escrever. Quem
 * fotografa uma rua esburacada está dizendo alguma coisa, e pedir que ela
 * redija aquilo é devolver o trabalho para quem já se deu ao trabalho.
 *
 * O resultado é gravado onde a transcrição de áudio já mora. A tabela chama-se
 * `message_transcriptions` por ter nascido do áudio, e o conceito é o mesmo:
 * texto que uma máquina extraiu de uma mídia. Duas tabelas para a mesma coisa
 * exigiriam duplicar `readableText()`, a consulta de histórico e a marcação na
 * linha do tempo — e um dos dois caminhos ficaria para trás.
 */
class ImageDescriptionService
{
    /**
     * Tipos que a visão lê.
     *
     * Vídeo fica de fora: o provedor recebe imagem, e mandar um quadro solto de
     * um vídeo descreveria o quadro, não o vídeo. Documento também — PDF exige
     * extração de texto, que é outro caminho.
     */
    private const READABLE_TYPES = ['image', 'sticker'];

    public function __construct(
        private readonly AiClient $client,
        private readonly ConversationMediaService $media,
        private readonly SystemSettingService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('ai.enabled', '0')
            && (bool) $this->settings->get('ai.vision.enabled', '0');
    }

    public function handles(ConversationMessage $message): bool
    {
        return in_array((string) $message->message_type, self::READABLE_TYPES, true)
            && $message->has_media
            && ! $this->hasUsableCaption($message);
    }

    /**
     * A legenda diz alguma coisa?
     *
     * Legenda de verdade dispensa a visão: a pessoa já escreveu o que queria
     * dizer, e descrever a foto por cima disso é gastar por informação que já
     * temos.
     *
     * Mas o WhatsApp manda legenda que não é legenda. A primeira imagem que
     * chegou neste sistema veio com `'` — uma aspa solta, provavelmente um
     * toque acidental no teclado. Para `blank()` aquilo é conteúdo, e a foto
     * ficou sem ser lida por causa de um caractere.
     *
     * O critério é ter letra ou número. Emoji sozinho, pontuação solta e espaço
     * não contam.
     */
    private function hasUsableCaption(ConversationMessage $message): bool
    {
        $letras = preg_replace('/[^\p{L}\p{N}]/u', '', (string) $message->body) ?? '';

        return mb_strlen($letras) > 0;
    }

    /**
     * Descreve a imagem da mensagem. Idempotente: descrição viva não é refeita.
     */
    public function describe(ConversationMessage $message, bool $force = false): ?MessageTranscription
    {
        if (! $this->enabled() || ! $this->handles($message)) {
            return null;
        }

        $existing = MessageTranscription::query()
            ->where('conversation_message_id', $message->id)
            ->whereIn('status', [TranscriptionStatus::Succeeded, TranscriptionStatus::Empty])
            ->latest('id')
            ->first();

        if ($existing && ! $force) {
            return $existing;
        }

        $medium = $this->media->ensure($message);

        if (! $medium?->isAvailable()) {
            // Sem arquivo não ha o que ler. Não é erro nosso: o WhatsApp guarda
            // mídia por tempo limitado, e o registro da mídia já diz isso.
            return null;
        }

        $bytes = $medium->contents();

        if ($bytes === null) {
            return null;
        }

        $record = MessageTranscription::create([
            'conversation_id' => $message->conversation_id,
            'conversation_message_id' => $message->id,
            'status' => TranscriptionStatus::Pending,
            'media_type' => $message->message_type,
            'media_bytes' => strlen($bytes),
            'attempt' => ($existing?->attempt ?? 0) + 1,
        ]);

        try {
            $run = $this->client->execute(
                AiRunPurpose::DescribeImage,
                new AiCompletionRequest(
                    systemPrompt: $this->systemPrompt(),
                    userPrompt: $this->userPrompt($message),
                    schemaName: 'descricao_de_imagem',
                    jsonSchema: $this->schema(),
                    imageDataUri: 'data:'.($medium->mimetype ?: 'image/jpeg').';base64,'.base64_encode($bytes),
                ),
                $this->schema(),
                'visao-v1',
                1,
                [
                    'conversation_id' => $message->conversation_id,
                    'source_message_id' => $message->id,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            $record->forceFill([
                'status' => TranscriptionStatus::Failed,
                'error_code' => 'IMAGE_DESCRIPTION_FAILED',
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            return $record;
        }

        if ($run->status !== AiRunStatus::Succeeded) {
            $record->forceFill([
                'ai_run_id' => $run->id,
                'status' => TranscriptionStatus::Failed,
                'error_code' => $run->error_code ?: 'IMAGE_DESCRIPTION_FAILED',
                'error_message' => mb_substr((string) $run->error_message, 0, 500),
            ])->save();

            return $record;
        }

        $data = $run->result ?? [];
        $text = $this->compose($data);

        DB::transaction(function () use ($record, $run, $text, $existing, $force): void {
            if ($force && $existing) {
                $existing->forceFill(['status' => TranscriptionStatus::Superseded])->save();
            }

            $record->forceFill([
                'ai_run_id' => $run->id,
                // Imagem sem nada aproveitável — uma figurinha de "bom dia" —
                // não é resposta, e o fluxo não deve tratá-la como opinião.
                'status' => $text === '' ? TranscriptionStatus::Empty : TranscriptionStatus::Succeeded,
                'provider' => $run->provider,
                'model' => $run->model,
                'text' => $text === '' ? null : $text,
                'transcribed_at' => now(),
            ])->save();
        });

        return $record->refresh();
    }

    /**
     * Junta o que o modelo viu num texto único.
     *
     * O texto escrito na foto vem primeiro. Quem fotografa um documento, uma
     * conta de luz ou um cartaz está mandando o que está escrito ali, e a
     * descrição visual daquilo — "papel branco sobre uma mesa" — não diz nada.
     *
     * @param  array<string, mixed>  $data
     */
    private function compose(array $data): string
    {
        $partes = [];

        $texto = trim((string) ($data['texto_na_imagem'] ?? ''));
        $descricao = trim((string) ($data['descricao'] ?? ''));

        if ($texto !== '') {
            $partes[] = 'Texto na imagem: '.$texto;
        }

        if ($descricao !== '') {
            $partes[] = $descricao;
        }

        return trim(implode("\n", $partes));
    }

    private function systemPrompt(): string
    {
        return <<<'TEXTO'
        Você descreve imagens recebidas por WhatsApp numa pesquisa de opinião pública.

        Regras:
        - Descreva o que a imagem mostra, de forma objetiva e curta.
        - Transcreva o texto legível na imagem, fielmente.
        - Seja breve: no máximo 300 caracteres na descrição e 700 na transcrição. Havendo mais texto que isso, transcreva o principal e pare.
        - Não descreva aparência física, roupa, raça, idade aparente ou estado de saúde de pessoas. Diga apenas quantas pessoas aparecem, se aparecerem.
        - Não presuma intenção, opinião ou sentimento de quem enviou.
        - Não invente. O que não estiver legível, deixe de fora.
        - Figurinha ou meme sem conteúdo relevante: devolva descrição vazia.
        TEXTO;
    }

    private function userPrompt(ConversationMessage $message): string
    {
        return $message->message_type === 'sticker'
            ? 'Esta é uma figurinha enviada na conversa. Descreva-a apenas se ela carregar informação; caso contrário devolva vazio.'
            : 'Esta é uma imagem enviada na conversa. Descreva o que ela mostra e transcreva o texto legível.';
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['descricao', 'texto_na_imagem', 'pessoas_visiveis', 'confidence'],
            'properties' => [
                'descricao' => [
                    'type' => 'string',
                    'description' => 'O que a imagem mostra, em uma ou duas frases, até 300 caracteres. Vazio quando não ha nada relevante.',
                ],
                /*
                 | O limite é do tamanho, e não do zelo.
                 |
                 | Um cartaz de campanha com doze temas listados fez o modelo
                 | transcrever tudo, estourar o teto de 2000 tokens de saída e
                 | devolver JSON cortado no meio — a execução custou 2396 tokens
                 | e produziu nada. Truncar no meio de uma chave é o pior dos
                 | dois mundos: paga-se pelo texto e perde-se o texto.
                 */
                'texto_na_imagem' => [
                    'type' => 'string',
                    'description' => 'Texto legível na imagem, transcrito fielmente, até 700 caracteres. Havendo mais, transcreva o principal. Vazio quando não ha texto.',
                ],
                'pessoas_visiveis' => [
                    'type' => 'integer',
                    'description' => 'Quantas pessoas aparecem. Nunca descreva a aparência delas.',
                ],
                'confidence' => [
                    'type' => 'number',
                    'description' => 'Confiança na leitura, de 0 a 1.',
                ],
            ],
        ];
    }
}
