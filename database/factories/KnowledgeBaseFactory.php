<?php

namespace Database\Factories;

use App\Enums\KnowledgeBaseStatus;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeBase>
 */
class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    public function definition(): array
    {
        $name = 'Base '.$this->faker->unique()->numberBetween(1, 100000);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'purpose' => 'Conteúdo oficial aprovado para consulta.',
            'usage_policy' => 'Somente conteúdo publicado e aprovado pela equipe responsável.',
            // Padrão rascunho: o estado que permite busca precisa ser pedido.
            'status' => KnowledgeBaseStatus::Draft,
            'version' => 1,
            'provider' => 'local',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeBaseStatus::Active,
            'approved_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => KnowledgeBaseStatus::Inactive]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => KnowledgeBaseStatus::Archived]);
    }
}
