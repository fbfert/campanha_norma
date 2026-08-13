<?php

namespace Tests\Feature;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\MediaStorageStatus;
use App\Enums\TranscriptionStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageMedium;
use App\Services\Ai\ImageDescriptionService;
use App\Services\Conversations\ConversationMediaService;
use App\Services\SystemSettingService;
use Database\Seeders\SendingSettingSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A foto que a pessoa mandou é lida, e não devolvida com um pedido.
 *
 * Foto e figurinha eram silêncio: o motor só avalia texto e a transcrição só
 * trata áudio. O que sobrava era pedir que a pessoa escrevesse o que já tinha
 * fotografado — devolver o trabalho para quem já se deu ao trabalho.
 */
class FotoRecebidaELidaTest extends TestCase
{
    use RefreshDatabase;

    /** Um PNG de 1x1 pixel: o menor arquivo que ainda é uma imagem de verdade. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
        $this->seed(SendingSettingSeeder::class);

        Storage::fake('local');

        app(SystemSettingService::class)->updateMany([
            'ai.enabled' => '1',
            'ai.vision.enabled' => '1',
            'conversations.media_storage_enabled' => '1',
        ]);

        // O provedor precisa estar escolhido, e não só ter credencial: sem
        // isto o gerenciador cai no provedor inerte e a execução volta como
        // "nenhum provedor configurado".
        config()->set('ai.provider', 'openai');
        config()->set('ai.providers.openai.key', 'chave-de-teste');
        config()->set('ai.providers.openai.model', 'gpt-4.1');
    }

    public function test_a_imagem_e_baixada_uma_vez_e_guardada_em_disco(): void
    {
        $mensagem = $this->imagemRecebida();

        $this->fakeProvider();

        $medium = app(ConversationMediaService::class)->ensure($mensagem);

        $this->assertNotNull($medium);
        $this->assertSame(MediaStorageStatus::Stored, $medium->status);
        $this->assertSame('image/png', $medium->mimetype);
        Storage::disk('local')->assertExists($medium->path);

        // Segunda chamada não volta ao provedor: o arquivo já está em disco, e
        // cada ida passa pelo Puppeteer que segura a sessão de pé.
        app(ConversationMediaService::class)->ensure($mensagem);

        Http::assertSentCount(1);
    }

    public function test_a_descricao_vira_texto_que_o_motor_le(): void
    {
        $mensagem = $this->imagemRecebida();

        $this->fakeProvider([
            'descricao' => 'Uma rua com buracos no asfalto.',
            'texto_na_imagem' => 'Rua das Flores',
            'pessoas_visiveis' => 0,
            'confidence' => 0.94,
        ]);

        $descricao = app(ImageDescriptionService::class)->describe($mensagem);

        $this->assertNotNull($descricao);
        $this->assertSame(TranscriptionStatus::Succeeded, $descricao->status);
        $this->assertSame('image', $descricao->media_type);

        // O texto escrito na foto vem primeiro: quem fotografa um cartaz está
        // mandando o que está escrito nele.
        $this->assertStringStartsWith('Texto na imagem: Rua das Flores', (string) $descricao->text);
        $this->assertStringContainsString('buracos no asfalto', (string) $descricao->text);

        // E é isso que o classificador e o gerador de resposta passam a ler.
        $this->assertStringContainsString('buracos no asfalto', $mensagem->refresh()->readableText());
    }

    public function test_figurinha_sem_conteudo_nao_vira_resposta(): void
    {
        $mensagem = $this->imagemRecebida('sticker');

        $this->fakeProvider([
            'descricao' => '',
            'texto_na_imagem' => '',
            'pessoas_visiveis' => 0,
            'confidence' => 0.9,
        ]);

        $descricao = app(ImageDescriptionService::class)->describe($mensagem);

        // Figurinha de "bom dia" não é opinião, e tratá-la como resposta faria
        // o fluxo perguntar sobre o nada.
        $this->assertSame(TranscriptionStatus::Empty, $descricao->status);
        $this->assertFalse($descricao->usableAsAnswer());
        $this->assertSame('', $mensagem->refresh()->readableText());
    }

    public function test_sem_arquivo_nao_ha_leitura_e_nada_e_cobrado(): void
    {
        $mensagem = $this->imagemRecebida();

        // Mídia antiga simplesmente não volta: a sessão do WhatsApp guarda por
        // tempo limitado. Isso não é erro nosso e não pode virar chamada paga.
        Http::fake([
            '127.0.0.1:3100/api/*' => Http::response(['success' => false, 'error' => 'ATTACHMENT_NOT_FOUND'], 404),
            'api.openai.com/*' => fn () => $this->fail('Não se chama a visão sem o arquivo em mãos.'),
        ]);

        $this->assertNull(app(ImageDescriptionService::class)->describe($mensagem));

        $this->assertSame(
            MediaStorageStatus::Unavailable,
            ConversationMessageMedium::where('conversation_message_id', $mensagem->id)->first()->status,
        );
    }

    public function test_esgotadas_as_tentativas_a_tela_explica_em_vez_de_tentar_mostrar(): void
    {
        $mensagem = $this->imagemRecebida();

        // A sessão do WhatsApp recusa a mídia. Isso acontece de verdade: a
        // biblioteca perdeu os módulos internos que usa para baixar.
        Http::fake([
            '127.0.0.1:3100/api/*' => Http::response(['success' => false, 'error' => 'ATTACHMENT_UNAVAILABLE'], 410),
        ]);

        $servico = app(ConversationMediaService::class);

        foreach (range(1, 3) as $ignorada) {
            $servico->ensure($mensagem);
        }

        $registro = ConversationMessageMedium::where('conversation_message_id', $mensagem->id)->first();

        $this->assertSame(MediaStorageStatus::Unavailable, $registro->status);
        $this->assertTrue($registro->exhausted());

        // `<img>` quebrado não avisa nada: quem abre a conversa vê um quadrado
        // cinza e conclui que o sistema está quebrado.
        $this->assertTrue(
            $registro->needsExplanation(),
            'Sem mais tentativas a fazer, a tela precisa dizer o que houve.',
        );
    }

    /**
     * @param  ?string  $legenda
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('legendas')]
    public function test_legenda_de_verdade_dispensa_a_visao(?string $legenda, bool $deveDescrever, string $porque): void
    {
        $mensagem = $this->imagemRecebida();
        $mensagem->forceFill(['body' => $legenda])->save();

        $this->assertSame(
            $deveDescrever,
            app(ImageDescriptionService::class)->handles($mensagem),
            $porque,
        );
    }

    /**
     * @return array<string, array{0: ?string, 1: bool, 2: string}>
     */
    public static function legendas(): array
    {
        return [
            'sem legenda' => [null, true, 'Foto sem texto é o caso central da visão.'],

            // A primeira imagem que chegou neste sistema veio com uma aspa
            // solta, provavelmente toque acidental no teclado. Para `blank()`
            // aquilo é conteúdo, e a foto ficou sem ser lida por um caractere.
            'aspa solta' => ["'", true, 'Uma aspa não é legenda.'],
            'só espaço' => ['   ', true, 'Espaço não diz nada.'],
            'só emoji' => ['👍', true, 'Emoji sozinho não descreve a foto.'],
            'só pontuação' => ['...', true, 'Reticências não são legenda.'],

            'legenda de verdade' => [
                'olha a rua aqui do meu bairro',
                false,
                'A pessoa já escreveu o que queria dizer: descrever por cima é gastar pelo que já temos.',
            ],
            'legenda curta mas real' => ['olha', false, 'Uma palavra é texto, e texto dispensa a visão.'],
        ];
    }

    public function test_a_chave_de_armazenamento_desliga_de_verdade(): void
    {
        app(SystemSettingService::class)->updateMany(['conversations.media_storage_enabled' => '0']);

        $mensagem = $this->imagemRecebida();

        // Configuração que mente é pior que configuração que falta.
        Http::fake(['*' => fn () => $this->fail('Armazenamento desligado não busca nada.')]);

        $this->assertNull(app(ConversationMediaService::class)->ensure($mensagem));
        $this->assertSame(0, ConversationMessageMedium::count());
    }

    public function test_desligada_a_visao_nada_e_lido(): void
    {
        app(SystemSettingService::class)->updateMany(['ai.vision.enabled' => '0']);

        $mensagem = $this->imagemRecebida();

        Http::fake(['*' => fn () => $this->fail('Visão desligada não chama nada.')]);

        $this->assertNull(app(ImageDescriptionService::class)->describe($mensagem));
    }

    /**
     * @param  array<string, mixed>|null  $resultado
     */
    private function fakeProvider(?array $resultado = null): void
    {
        Http::fake([
            '127.0.0.1:3100/api/*' => Http::response([
                'success' => true,
                'data' => ['data' => self::PNG, 'mimetype' => 'image/png', 'filename' => 'foto.png'],
            ], 200),

            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-teste',
                'model' => 'gpt-4.1',
                'choices' => [[
                    'message' => ['content' => json_encode($resultado ?? [
                        'descricao' => 'Uma rua.',
                        'texto_na_imagem' => '',
                        'pessoas_visiveis' => 0,
                        'confidence' => 0.9,
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'total_tokens' => 160],
            ], 200),
        ]);
    }

    private function imagemRecebida(string $tipo = 'image'): ConversationMessage
    {
        $contact = Contact::factory()->create(['phone_normalized' => '5549988887777']);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'external_chat_id' => '5549988887777@c.us',
        ]);

        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => ConversationMessageDirection::Incoming,
            'origin' => ConversationMessageOrigin::Incoming,
            'message_type' => $tipo,
            'body' => null,
            'has_media' => true,
            'status' => ConversationMessageStatus::Received,
            'external_message_id' => 'wamid.teste123',
            'external_chat_id' => '5549988887777@c.us',
            'sender_phone_snapshot' => '5549988887777',
            'received_at' => now(),
        ]);
    }
}
