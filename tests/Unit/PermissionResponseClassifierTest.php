<?php

namespace Tests\Unit;

use App\Enums\PermissionResponseClassification;
use App\Services\ConversationAutomation\PermissionResponseClassifier;
use App\Services\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes puros do classificador deterministico: sem banco, sem framework.
 * As configuracoes sao injetadas por um duble do servico de settings.
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
                    'conversation_automation.no_expressions' => 'nao|nao quero|nao posso|agora nao|nao obrigado|nao obrigada|prefiro nao|sem interesse|nao tenho interesse|deixa pra la|talvez depois|negativo',
                    'conversation_automation.opt_out_expressions' => 'sair|parar|pare|cancelar|descadastrar|remover|me remova|nao quero receber mensagens|nao quero mais receber|nao me mande mais|nao envie mais|nao perturbe|me tire da lista|bloquear|spam|stop|unsubscribe',
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
            'sim maiusculo' => ['SIM'],
            'sim com pontuacao' => ['Sim!'],
            'sim com espacos' => ['   sim   '],
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
            'nao com acento' => ['não'],
            'nao maiusculo' => ['NAO'],
            'nao obrigado' => ['Nao obrigado'],
            'nao obrigada com acento' => ['Não, obrigada'],
            'agora nao' => ['agora nao'],
            'nao posso' => ['nao posso'],
            'prefiro nao' => ['prefiro nao'],
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
            'nao quero receber mensagens' => ['nao quero receber mensagens'],
            'com acento' => ['não quero receber mensagens'],
            'nao me mande mais' => ['nao me mande mais'],
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
        $this->assertSame(PermissionResponseClassification::OptOut, $this->classify('nao quero receber mensagens'));
    }

    /** @return array<string, array{0: string}> */
    public static function respostasAmbiguas(): array
    {
        return [
            'vazio' => [''],
            'somente espacos' => ['   '],
            'somente pontuacao' => ['???'],
            'pergunta de volta' => ['quem e voce'],
            'texto longo sem expressao' => ['estou no trabalho agora e nao consigo falar direito sobre isso'],
            'texto longo com positiva' => ['bom dia tudo bem com voce entao me diga do que se trata isso ai'],
            'duvida' => ['depende do assunto'],
            'positiva e negativa' => ['sim e nao'],
            'numero solto' => ['12345'],
        ];
    }

    #[DataProvider('respostasAmbiguas')]
    public function test_classifica_ambiguo(string $text): void
    {
        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classify($text), "Falhou para: {$text}");
    }

    public function test_texto_longo_nao_vira_positivo_por_aproximacao(): void
    {
        $texto = 'eu nao sei se posso responder isso agora mas talvez sim quem sabe depois';

        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classify($texto));
    }

    public function test_correspondencia_exata_vale_mesmo_com_muitas_palavras(): void
    {
        // Frase longa configurada explicitamente deve ser aceita como exata.
        $classification = $this->classify('sim pode perguntar o que voce quiser agora', [
            'conversation_automation.yes_expressions' => 'sim pode perguntar o que voce quiser agora',
            'conversation_automation.short_answer_max_words' => 3,
        ]);

        $this->assertSame(PermissionResponseClassification::PermissionYes, $classification);
    }

    public function test_nao_casa_expressao_dentro_de_outra_palavra(): void
    {
        // "sim" nao pode casar dentro de "simplesmente"; "pode" nao dentro de "poderia".
        $this->assertSame(PermissionResponseClassification::Ambiguous, $this->classify('simplesmente incrivel'));
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

        $this->assertSame('nao obrigado', $result['normalized']);
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

        $this->assertSame('acao e coracao', $classifier->normalize('Ação; e Coração!'));
        $this->assertSame('nao', $classifier->normalize('NÃO...'));
        $this->assertSame('', $classifier->normalize('!!!'));
    }
}
