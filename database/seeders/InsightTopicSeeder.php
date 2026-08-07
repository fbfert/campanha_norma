<?php

namespace Database\Seeders;

use App\Models\InsightTopic;
use Illuminate\Database\Seeder;

/**
 * Taxonomia inicial de temas.
 *
 * O tema de fallback e obrigatório e nunca pode ser excluído ou desativado: ele
 * e o destino de qualquer saída do modelo que não case com um tema cadastrado.
 */
class InsightTopicSeeder extends Seeder
{
    public function run(): void
    {
        /*
         | O vocabulário e a parte que decide, porque a recuperação e lexical: um
         | tema so alcança a resposta se alguma palavra dele aparecer no que a
         | pessoa escreveu. Por isso a lista traz a palavra da rua, não a do
         | documento oficial — quem responde escreve "posto", "estrada de chão"
         | e "sinal de celular", e nunca "unidade básica de saúde".
         |
         | O recorte também e regional. Na Serra catarinense, maçã, leite, pinus
         | e CTG aparecem em conversa sobre a cidade com a mesma naturalidade com
         | que praia apareceria no litoral.
         */
        $topics = [
            ['saude', 'Saúde', 'saúde pública|hospital|posto de saúde|posto|upa|sus|médico|médicos|especialidades médicas|remédio|medicamentos|consulta|exame|fila de espera|ambulância|dentista|saúde mental|psicólogo|atendimento', '#e11d48', 10],
            ['educacao', 'Educação', 'escola|escolas|professor|professores|creche|vaga na creche|universidade|ensino|merenda|merenda escolar|material escolar|transporte escolar|alfabetização|ensino médio|ensino fundamental|escola do interior|reforma da escola|sala de aula', '#2563eb', 20],
            ['seguranca', 'Segurança', 'segurança pública|polícia|policiamento|delegacia|violência|criminalidade|assalto|roubo|furto|iluminação pública|câmeras|drogas|vandalismo', '#7c3aed', 30],
            ['infraestrutura', 'Infraestrutura', 'obras|obra parada|saneamento|esgoto|água|água encanada|energia|calçada|calçamento|meio-fio|ponte|drenagem|bueiro|alagamento|enchente|iluminação', '#0891b2', 40],
            ['estradas', 'Estradas', 'estrada|estrada de chão|estrada rural|rodovia|asfalto|buraco|buracos|pavimentação|cascalho|patrolamento|sinalização|acostamento|br 101|br 282|sc 108|sc 114', '#b45309', 50],
            ['agricultura', 'Agricultura', 'agricultor|agricultores|produtor rural|lavoura|pecuária|agronegócio|pequeno produtor|agricultura familiar|leite|gado|plantação|maçã|pinus|reflorestamento|trator|assistência técnica|epagri|cooperativa|pequena propriedade', '#16a34a', 60],
            ['emprego', 'Emprego', 'trabalho|desemprego|renda|emprego formal|vaga de emprego|primeiro emprego|salário|indústria|frigorífico|estágio|carteira assinada|qualificação profissional|curso profissionalizante|curso técnico|capacitação', '#ca8a04', 70],
            ['meio_ambiente', 'Meio ambiente', 'meio ambiente|ambiental|poluição|lixo|coleta de lixo|aterro|reciclagem|preservação|conservação|rio|nascente|mata|queimada|agrotóxico|arborização', '#059669', 80],
            ['assistencia_social', 'Assistência social', 'assistência social|cras|creas|bolsa família|auxílio|vulnerabilidade|idoso|aposentado|pessoa com deficiência|cesta básica|moradia|aluguel social|abrigo|mãe solo', '#db2777', 90],
            ['mobilidade', 'Mobilidade', 'transporte público|ônibus|linha de ônibus|horário de ônibus|mobilidade urbana|trânsito|ciclovia|passagem|van|estacionamento|acessibilidade', '#4f46e5', 100],
            // Cultura e esporte viviam no mesmo tema. Quem pede quadra coberta e
            // quem pede biblioteca falam de coisas diferentes, e juntá-las
            // escondia as duas: nenhum relatório mostrava qual das duas puxava o
            // número. O esporte ainda e a origem do projeto social da Rainbow.
            ['cultura', 'Cultura', 'cultura|evento cultural|biblioteca|teatro|música|festa|festival|tradição|ctg|artesanato|cinema|coral|banda|patrimônio', '#9333ea', 110],
            ['esporte', 'Esporte e lazer', 'esporte|lazer|quadra|quadra coberta|ginásio|campo de futebol|escolinha de futebol|escolinha|academia|academia ao ar livre|atleta|competição|campeonato|vôlei|futsal|pista de caminhada|praça|parque', '#ea580c', 115],
            ['desigualdade_regional', 'Desigualdade regional', 'interior|municípios menores|cidade pequena|região esquecida|desigualdade regional|serra catarinense|planalto serrano|esquecido|capital|repasse|verba', '#0f766e', 120],
            ['empreendedorismo', 'Empreendedorismo', 'empreendedor|empreendedora|empreendedorismo|pequeno negócio|microempresa|mei|abrir empresa|burocracia|crédito|financiamento|sebrae|comércio local|feira|autônomo|jovem empreendedor', '#0d9488', 130],
            // A Serra vive de turismo e ninguém tinha onde encaixar isso: quem
            // sugeriu "investir no turismo em Lages" caiu em Outros. O
            // vocabulário puxa para o que a região oferece — turismo rural,
            // cânion, neve, vinícola — porque é assim que as pessoas daqui
            // descrevem o que veem.
            ['turismo', 'Turismo', 'turismo|turista|turistas|turístico|pousada|hotel|hospedagem|turismo rural|hotel fazenda|cânion|canion|cachoeira|trilha|mirante|neve|vinícola|vinicola|enoturismo|atrativo|receptivo|gastronomia|restaurante', '#0369a1', 135],
            ['tecnologia', 'Tecnologia e conectividade', 'internet|sinal|sinal de celular|conectividade|banda larga|fibra óptica|inclusão digital|computador|informática|telefonia|antena', '#6366f1', 140],
            ['mulher', 'Mulher', 'mulher|mulheres|feminino|maternidade|violência doméstica|igualdade|liderança feminina|mulher empreendedora', '#be185d', 150],
            // Criado pela tela, não pelo seeder, e por isso ficou sem
            // vocabulário. Cobre quem pergunta pela oferta de ensino superior:
            // e a porta de entrada mais concreta que a Norma tem a oferecer.
            ['ead', 'EAD Unifacvest', 'ead|ensino a distância|semipresencial|unifacvest|polo|rainbow|educa santa catarina|desbloqueia|matrícula|bolsa de estudo|faculdade|graduação|pós-graduação|ensino superior', '#7c2d12', 160],
        ];

        foreach ($topics as [$slug, $name, $synonyms, $color, $order]) {
            InsightTopic::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'synonyms' => $synonyms,
                    'color' => $color,
                    'display_order' => $order,
                    'is_active' => true,
                    'is_fallback' => false,
                ]
            );
        }

        InsightTopic::query()->updateOrCreate(
            ['slug' => 'outros'],
            [
                'name' => 'Outros / nao classificado',
                'description' => 'Destino obrigatório quando nenhum tema cadastrado corresponde a saída do modelo.',
                'synonyms' => 'outro|outros|não classificado|indefinido|geral',
                'color' => '#64748b',
                'display_order' => 999,
                'is_active' => true,
                'is_fallback' => true,
            ]
        );
    }
}
