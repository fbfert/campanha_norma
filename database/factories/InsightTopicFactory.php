<?php

namespace Database\Factories;

use App\Models\InsightTopic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InsightTopic>
 */
class InsightTopicFactory extends Factory
{
    protected $model = InsightTopic::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name, '_'),
            'synonyms' => null,
            'color' => '#2563eb',
            'display_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
            'is_fallback' => false,
        ];
    }

    public function fallback(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Outros / nao classificado',
            'slug' => 'outros',
            'is_fallback' => true,
            'display_order' => 999,
        ]);
    }
}
