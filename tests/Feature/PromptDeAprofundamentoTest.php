<?php

namespace Tests\Feature;

use App\Enums\AiRunPurpose;
use App\Services\Ai\AiPromptRepository;
use App\Services\SystemSettingService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt de aprofundamento.
 *
 * O prompt e o único lugar onde se decide a qualidade da pergunta gerada, e um
 * erro ali contamina o dado da pesquisa inteira sem produzir erro nenhum. Nas
 * primeiras conversas reais o modelo ofereceu alternativas para a pessoa
 * escolher e sugeriu propostas da candidata dentro da pergunta — as duas coisas
 * transformam pesquisa em confirmação.
 */
class PromptDeAprofundamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingSeeder::class);
    }

    /**
     * Versão configurada que não existe em disco derruba toda geração, e so na
     * hora em que alguém responde.
     */
    public function test_as_versoes_configuradas_existem_em_disco(): void
    {
        $prompts = app(AiPromptRepository::class);

        foreach ([$prompts->activeVersion(AiRunPurpose::GenerateReply), $prompts->activeGroundedResponseVersion()] as $versao) {
            $texto = $prompts->get(AiRunPurpose::GenerateReply, $versao);

            $this->assertNotSame('', trim($texto), "O prompt {$versao} esta vazio.");
        }
    }

    public function test_o_prompt_ativo_proibe_pergunta_fechada_e_sugestao_de_solucao(): void
    {
        $prompts = app(AiPromptRepository::class);
        $texto = $prompts->get(AiRunPurpose::GenerateReply, $prompts->activeGroundedResponseVersion());

        $this->assertStringContainsString('SEMPRE aberta', $texto);
        $this->assertStringContainsString('NUNCA ofereca alternativas', $texto); // ortografia:ignorar - trecho literal do prompt, escrito sem acento
        $this->assertStringContainsString('NUNCA sugira solucao', $texto); // ortografia:ignorar - trecho literal do prompt, escrito sem acento
    }

    /**
     * As regras de segurança da versão anterior continuam valendo: a versão
     * nova acrescenta qualidade de pergunta, e não substitui o que protegia.
     */
    public function test_a_versao_nova_preserva_as_proibicoes_de_seguranca(): void
    {
        $prompts = app(AiPromptRepository::class);
        $anterior = $prompts->get(AiRunPurpose::GenerateReply, 'v2');
        $atual = $prompts->get(AiRunPurpose::GenerateReply, $prompts->activeGroundedResponseVersion());

        foreach ([
            'Prometer acao, beneficio', // ortografia:ignorar - trechos literais do prompt, escrito sem acento
            'Pedir voto',
            'Comparar com adversarios', // ortografia:ignorar
            'Simular intimidade',
            'Toda afirmacao factual precisa sair de um trecho oficial', // ortografia:ignorar
        ] as $regra) {
            $this->assertStringContainsString($regra, $anterior);
            $this->assertStringContainsString($regra, $atual, "A regra \"{$regra}\" se perdeu na versão nova.");
        }
    }

    /**
     * Trocar a versão e mudança de configuração, não de deploy: o repositório
     * precisa continuar servindo qualquer versão que exista em disco.
     */
    public function test_a_versao_pode_ser_trocada_por_configuracao(): void
    {
        $prompts = app(AiPromptRepository::class);
        $disponiveis = $prompts->versions(AiRunPurpose::GenerateReply);

        $this->assertContains('v2', $disponiveis);
        $this->assertContains('v3', $disponiveis);

        app(SystemSettingService::class);
        $this->assertNotSame('', trim($prompts->get(AiRunPurpose::GenerateReply, 'v2')));
    }
}
