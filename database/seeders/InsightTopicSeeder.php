<?php

namespace Database\Seeders;

use App\Models\InsightTopic;
use Illuminate\Database\Seeder;

/**
 * Taxonomia inicial de temas.
 *
 * O tema de fallback e obrigatorio e nunca pode ser excluido ou desativado: ele
 * e o destino de qualquer saida do modelo que nao case com um tema cadastrado.
 */
class InsightTopicSeeder extends Seeder
{
    public function run(): void
    {
        $topics = [
            ['saude', 'Saude', 'saude publica|hospital|posto de saude|sus|medico|medicos|especialidades medicas|remedio|medicamentos', '#e11d48', 10],
            ['educacao', 'Educacao', 'escola|escolas|professor|professores|creche|universidade|ensino|merenda', '#2563eb', 20],
            ['seguranca', 'Seguranca', 'seguranca publica|policia|violencia|criminalidade|assalto|roubo|iluminacao publica', '#7c3aed', 30],
            ['infraestrutura', 'Infraestrutura', 'obras|saneamento|esgoto|agua|energia|calcada|ponte|drenagem', '#0891b2', 40],
            ['estradas', 'Estradas', 'estrada|rodovia|asfalto|buraco|buracos|pavimentacao|br 101|sc 108', '#b45309', 50],
            ['agricultura', 'Agricultura', 'agricultor|agricultores|produtor rural|lavoura|pecuaria|agronegocio|pequeno produtor', '#16a34a', 60],
            ['emprego', 'Emprego', 'trabalho|desemprego|renda|emprego formal|qualificacao profissional|curso profissionalizante', '#ca8a04', 70],
            ['meio_ambiente', 'Meio ambiente', 'meio ambiente|ambiental|poluicao|lixo|reciclagem|preservacao|rio|mata', '#059669', 80],
            ['assistencia_social', 'Assistencia social', 'assistencia social|cras|creas|bolsa familia|auxilio|vulnerabilidade|idoso|pessoa com deficiencia', '#db2777', 90],
            ['mobilidade', 'Mobilidade', 'transporte publico|onibus|mobilidade urbana|transito|ciclovia|passagem', '#4f46e5', 100],
            ['cultura', 'Cultura', 'cultura|esporte|lazer|evento cultural|biblioteca|teatro|praca', '#9333ea', 110],
            ['desigualdade_regional', 'Desigualdade regional', 'interior|municipios menores|cidade pequena|regiao esquecida|desigualdade regional', '#0f766e', 120],
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
                'description' => 'Destino obrigatorio quando nenhum tema cadastrado corresponde a saida do modelo.',
                'synonyms' => 'outro|outros|nao classificado|indefinido|geral',
                'color' => '#64748b',
                'display_order' => 999,
                'is_active' => true,
                'is_fallback' => true,
            ]
        );
    }
}
