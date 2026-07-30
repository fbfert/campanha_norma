<?php

namespace Tests\Unit;

use App\Enums\PermissionResponseClassification;
use App\Services\ConversationAutomation\PermissionResponseClassifier;
use App\Services\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes puros do classificador determinístico: sem banco, sem framework.
 * As configurações são injetadas por um duble do serviço de settings.
 */
class PermissionResponseClassifierTest extends TestCase
{
    private function classifier(array $overrides = []): PermissionResponseClassifier
    {
        $settings = new class($overrides) extends SystemSettingService
        {
            public function __construct(private readonly array $overrides) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->overrides[$key] ?? match ($key) {
                    'conversation_automation.yes_expressions' => 'sim|claro|pode|pode sim|pode perguntar|manda|manda ai|pergunte|pergunta|quero|aceito|ok|beleza|positivo|com certeza|vamos|bora|tudo bem',
                    'conversation_automation.no_expressions' => 'não|não quero|não posso|agora não|não obrigado|não obrigada|prefiro não|sem interesse|não tenho interesse|deixa pra la|talvez depois|negativo',
                    'conversation_automation.opt_out_expressions' => 'sair|parar|pare|cancelar|descadastrar|remover|me remova|não quero receber mensagens|não quero mais receber|não me mande mais|não envie mais|não perturbe|me tire da lista|bloquear|spam|stop|unsubscribe',
                    'conversation_automation.short_answer_max_words' => 6,
                    default => $default,
                };
            }
        };

        return new PermissionResponseClassifier($settings);
    }

    private function classify(string $text, array $overrides = []): PermissionResponseClassification
    {
        return $this->classifier($overrides)->classify($text)['classification'];
    }

    /** @return array<string, array{0: string}> */
    public static function respostasPositivas(): array
    {
        return [
            'sim' => ['sim'],
            'sim maiúsculo' => ['SIM'],
            'sim com pontuação' => ['Sim!'],
            'sim com espaços' => ['   sim   '],
            'claro' => ['Claro'],
            'pode' => ['pode'],
            'pode sim' => ['Pode sim'],
            'pode perguntar' => ['Sim, pode perguntar'],
            'manda' => ['manda'],
            'manda ai' => ['Manda ai'],
            'pergunte' => ['Pergunte'],
            'quero' => ['quero'],
            'aceito' => ['Aceito'],
            'ok' => ['ok'],
            'beleza' => ['Beleza'],
            'com certeza' => ['Com certeza'],
            'com acento' => ['sim, pode perguntar'],
            'com emoji' => ['sim 👍'],
        ];
    }

    #[DataProvider('respostasPositivas')]
    public function test_classifica_respostas_positivas(string $text): void
    {
        $this->assertSame(PermissionResponseClassification::PermissionYes, $this->classify($text), "Falhou para: {$text}");
    }

    /** @return array<string, array{0: string}> */
    public static function respostasNegativas(): array
    {
        return [
            'nao' => ['nao'],
            'não com acento' => ['não'],
            'não maiúsculo' => ['NÃO'],
            'não obrigado' => ['Não obrigado'],
            'não obrigada com acento' => ['Não, obrigada'],
            'agora não' => ['agora não'],
            'não posso' => ['não posso'],
            'prefiro não' => ['prefiro não'],
            'sem interesse' => ['sem interesse'],
            'talvez depois' => ['talvez depois'],
            'negativo' => ['negativo'],
        ];
    }

    #[DataProvider('respostasNegativas')]
    public function test_classifica_respostas_negativas(string $text): void
    {
        $this->assertSame(PermissionResponseClassification::PermissionNo, $this->classify($text), "Falhou para: {$text}");
    }

    /** @return array<string, array{0: string}> */
    public static function respostasOptOut(): array
    {
        return [
            'sair' => ['sair'],
            'parar' => ['parar'],
            'pare' => ['Pare'],
            'cancelar' => ['cancelar'],
            'descadastrar' => ['descadastrar'],
            'me remova' => ['me remova'],
            'não quero receber mensagens' => ['não quero receber mensagens'],
            'com acento' => ['não quero receber mensagens'],
            'não me mande mais' => ['não me mande mais'],
            'me tire da lista' => ['me tire da lista'],
            'stop' => ['STOP'],
            'unsubscribe' => ['unsubscribe'],
            'spam' => ['isso e spam'],
        ];
    }

    #[DataProvider('respostasOptOut')]
    public function test_classifica_opt_out(string $text): void
    {
        $this->assertSame(PermissionResponseClassification::OptOut, $this->classify($text), "Falhou para: {$text}");
    }

    public function test_opt_out_tem_prioridade_absoluta_sobre_positiva(): void
    {
        $this->assertSame(PermissionResponseClassification::OptOut, $this->classify('sim, mas me remova'));
        $this->assertSame(PermissionResponseClassification::OptOut, $this->classify('ok pode parar'));
    }

    public function test_opt_out_tem_prioridade_sobre_negativa(): void
    {
        $this->assertSame(PermissionResponseClassification::OptOut, $this->classify('não quero receber mensagens'));
    }

    /** @return array<string, array{0: string}> */
    public static function respostasAmbiguas(): array
    {
        return [
            'vazio' => [''],
            'somente espaços' => ['   '],
            'somente pontuação' => ['???'],
            'pergunta de volta' => ['quem e você'],
            'texto longo sem expressão' => ['estou no trabalho agora e não consigo falar direito sobre isso'],
            'texto longo com positiva' => ['bom dia tudo bem com você então me diga do que se trata isso ai'],
            'duvida' => ['depende do assunto'],
            'positiva e negativa' => ['sim e não'],
            'número solto' => ['12345'],
        ];
    }

    #[DataProvider('respostasAmbiguas')]
    public function test_classifica_ambiguo(string $text): void
    {
        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classify($text), "Falhou para: {$text}");
    }

    public function test_texto_longo_nao_vira_positivo_por_aproximacao(): void
    {
        $texto = 'eu não sei se posso responder isso agora mas talvez sim quem sabe depois';

        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classify($texto));
    }

    public function test_correspondencia_exata_vale_mesmo_com_muitas_palavras(): void
    {
        // Frase longa configurada explicitamente deve ser aceita como exata.
        $classification = $this->classify('sim pode perguntar o que você quiser agora', [
            'conversation_automation.yes_expressions' => 'sim pode perguntar o que você quiser agora',
            'conversation_automation.short_answer_max_words' => 3,
        ]);

        $this->assertSame(PermissionResponseClassification::PermissionYes, $classification);
    }

    public function test_nao_casa_expressao_dentro_de_outra_palavra(): void
    {
        // "sim" não pode casar dentro de "simplesmente"; "pode" não dentro de "poderia".
        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classify('simplesmente incrível'));
    }

    public function test_listas_sao_editaveis_sem_alterar_codigo(): void
    {
        $classification = $this->classify('bora la', [
            'conversation_automation.yes_expressions' => 'bora la',
        ]);

        $this->assertSame(PermissionResponseClassification::PermissionYes, $classification);
    }

    public function test_normalizacao_preserva_o_texto_original_no_retorno(): void
    {
        $result = $this->classifier()->classify('  Não, Obrigado!  ');

        $this->assertSame('nao obrigado', $result['normalized']); // ortografia:ignorar - saida normalizada nao tem acento
        $this->assertSame(PermissionResponseClassification::PermissionNo, $result['classification']);
    }

    public function test_retorno_inclui_motivo_para_auditoria(): void
    {
        $result = $this->classifier()->classify('sim');

        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('matched', $result);
        $this->assertNotSame('', $result['reason']);
    }

    public function test_normalize_remove_acentos_e_pontuacao(): void
    {
        $classifier = $this->classifier();

        $this->assertSame('acao e coracao', $classifier->normalize('Ação; e Coração!')); // ortografia:ignorar - saida normalizada nao tem acento
        $this->assertSame('nao', $classifier->normalize('NÃO...'));
        $this->assertSame('', $classifier->normalize('!!!'));
    }
}
