<?php

namespace Tests\Feature;

use App\Contracts\AudioTranscriber;
use App\Data\Ai\TranscriptionResult;
use App\Enums\TranscriptionStatus;
use App\Models\AiRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessageTranscription;
use App\Models\SystemSetting;
use App\Services\Ai\AudioTranscriptionService;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transcrição de áudio recebido.
 *
 * Cinco áudios chegaram a base e nenhum produziu classificação, insight ou
 * resposta: a mensagem entra com corpo vazio, e o motor so avalia texto. Para
 * quem falou, o sistema simplesmente não reagiu.
 *
 * A transcrição não sobrescreve o corpo da mensagem. Ela fica ao lado, marcada
 * como transcrição, porque numa pesquisa "a pessoa escreveu" e "uma máquina
 * ouviu" não têm o mesmo peso.
 */
class TranscricaoDeAudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->ligar();
    }

    public function test_o_texto_fica_em_tabela_propria_ligada_a_conversa(): void
    {
        $mensagem = $this->audio();
        $this->transcritorDevolvendo('Acho que falta iluminação na praça.');

        $registro = app(AudioTranscriptionService::class)->transcribe($mensagem, 'bytes-do-audio', 'audio.ogg');

        $this->assertSame(TranscriptionStatus::Succeeded, $registro->status);
        $this->assertSame('Acho que falta iluminação na praça.', $registro->text);
        $this->assertSame($mensagem->conversation_id, $registro->conversation_id);
        $this->assertSame($mensagem->id, $registro->conversation_message_id);

        $this->assertSame('', (string) $mensagem->refresh()->body, 'O corpo continua sendo o registro do que chegou.');
        $this->assertSame('Acho que falta iluminação na praça.', $mensagem->readableText());
    }

    public function test_o_custo_entra_no_mesmo_lugar_dos_demais(): void
    {
        $mensagem = $this->audio();
        $this->transcritorDevolvendo('Falta transporte à noite.', duracaoEmSegundos: 120);

        app(AudioTranscriptionService::class)->transcribe($mensagem, 'bytes', 'audio.ogg');

        $run = AiRun::query()->where('purpose', 'transcribe_audio')->first();

        $this->assertNotNull($run, 'A transcrição precisa aparecer no painel de custo junto com o resto.');
        $this->assertSame($mensagem->conversation_id, $run->conversation_id);
    }

    /**
     * Áudio sem fala reconhecível não e resposta. Tratar silêncio como opinião
     * inventaria dado de pesquisa.
     */
    public function test_audio_sem_fala_nao_vira_resposta(): void
    {
        $mensagem = $this->audio();
        $this->transcritorDevolvendo('   ');

        $registro = app(AudioTranscriptionService::class)->transcribe($mensagem, 'bytes', 'audio.ogg');

        $this->assertSame(TranscriptionStatus::Empty, $registro->status);
        $this->assertNull($registro->text);
        $this->assertFalse($registro->usableAsAnswer());
        $this->assertSame('', $mensagem->readableText());
    }

    public function test_nao_transcreve_duas_vezes_a_mesma_mensagem(): void
    {
        $mensagem = $this->audio();
        $this->transcritorDevolvendo('Primeira leitura.');

        $servico = app(AudioTranscriptionService::class);
        $servico->transcribe($mensagem, 'bytes', 'audio.ogg');
        $servico->transcribe($mensagem, 'bytes', 'audio.ogg');

        $this->assertSame(1, MessageTranscription::query()->count());
    }

    /**
     * Refazer preserva a anterior: comparar duas leituras do mesmo áudio e o
     * que permite descobrir que um modelo entendeu melhor que o outro.
     */
    public function test_refazer_guarda_a_transcricao_anterior(): void
    {
        $mensagem = $this->audio();
        $this->transcritorDevolvendo('Leitura antiga.');

        app(AudioTranscriptionService::class)->transcribe($mensagem, 'bytes', 'audio.ogg');

        // Resolve de novo: o serviço recebe o transcritor por injeção, e a
        // instância antiga carregaria o transcritor antigo.
        $this->transcritorDevolvendo('Leitura nova, mais fiel.');
        app(AudioTranscriptionService::class)->transcribe($mensagem, 'bytes', 'audio.ogg', force: true);

        $this->assertSame(2, MessageTranscription::query()->count());
        $this->assertSame(1, MessageTranscription::query()->where('status', TranscriptionStatus::Superseded)->count());
        $this->assertSame('Leitura nova, mais fiel.', $mensagem->transcription()?->text);
    }

    public function test_desligado_nao_chama_o_provedor(): void
    {
        $this->gravar('ai.transcription.enabled', '0');
        $mensagem = $this->audio();

        $this->app->bind(AudioTranscriber::class, fn () => new class implements AudioTranscriber
        {
            public function transcribe(string $audio, string $filename): TranscriptionResult
            {
                throw new \RuntimeException('não deveria ter sido chamado');
            }
        });

        $this->assertNull(app(AudioTranscriptionService::class)->transcribe($mensagem, 'bytes', 'audio.ogg'));
        $this->assertSame(0, MessageTranscription::query()->count());
    }

    private function ligar(): void
    {
        $this->gravar('ai.transcription.enabled', '1');
    }

    private function gravar(string $chave, string $valor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $chave],
            ['group' => 'ai', 'value' => $valor, 'type' => 'string', 'is_public' => false]
        );

        app(SystemSettingService::class)->forget();
    }

    private function transcritorDevolvendo(string $texto, ?int $duracaoEmSegundos = 30): void // ortografia:ignorar - identificador, comparado pelo codigo
    {
        $this->app->bind(AudioTranscriber::class, fn () => new class($texto, $duracaoEmSegundos) implements AudioTranscriber
        {
            public function __construct(private readonly string $texto, private readonly ?int $duracaoEmSegundos) {}

            public function transcribe(string $audio, string $filename): TranscriptionResult
            {
                return new TranscriptionResult(
                    text: $this->texto,
                    model: 'transcricao-de-teste',
                    language: 'pt',
                    durationSeconds: $this->duracaoEmSegundos,
                    latencyMs: 900,
                );
            }
        });
    }

    private function audio(): ConversationMessage
    {
        $conversa = Conversation::factory()->create(['contact_id' => Contact::factory()->create()->id]);

        return ConversationMessage::factory()->create([
            'conversation_id' => $conversa->id,
            'direction' => 'incoming',
            'message_type' => 'ptt',
            'has_media' => true,
            'body' => '',
        ]);
    }
}
