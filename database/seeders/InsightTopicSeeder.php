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
        $topics = [
            ['saude', 'Saúde', 'saúde pública|hospital|posto de saúde|sus|médico|médicos|especialidades médicas|remédio|medicamentos', '#e11d48', 10],
            ['educacao', 'Educação', 'escola|escolas|professor|professores|creche|universidade|ensino|merenda', '#2563eb', 20],
            ['seguranca', 'Segurança', 'segurança pública|polícia|violência|criminalidade|assalto|roubo|iluminação pública', '#7c3aed', 30],
            ['infraestrutura', 'Infraestrutura', 'obras|saneamento|esgoto|água|energia|calçada|ponte|drenagem', '#0891b2', 40],
            ['estradas', 'Estradas', 'estrada|rodovia|asfalto|buraco|buracos|pavimentação|br 101|sc 108', '#b45309', 50],
            ['agricultura', 'Agricultura', 'agricultor|agricultores|produtor rural|lavoura|pecuária|agronegócio|pequeno produtor', '#16a34a', 60],
            ['emprego', 'Emprego', 'trabalho|desemprego|renda|emprego formal|qualificação profissional|curso profissionalizante', '#ca8a04', 70],
            ['meio_ambiente', 'Meio ambiente', 'meio ambiente|ambiental|poluição|lixo|reciclagem|preservação|rio|mata', '#059669', 80],
            ['assistencia_social', 'Assistência social', 'assistência social|cras|creas|bolsa família|auxílio|vulnerabilidade|idoso|pessoa com deficiência', '#db2777', 90],
            ['mobilidade', 'Mobilidade', 'transporte público|ônibus|mobilidade urbana|trânsito|ciclovia|passagem', '#4f46e5', 100],
            ['cultura', 'Cultura', 'cultura|esporte|lazer|evento cultural|biblioteca|teatro|praça', '#9333ea', 110],
            ['desigualdade_regional', 'Desigualdade regional', 'interior|municípios menores|cidade pequena|região esquecida|desigualdade regional', '#0f766e', 120],
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
