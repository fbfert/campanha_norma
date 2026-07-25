<?php

namespace Database\Factories;

use App\Models\ConversationTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversationTag> */
class ConversationTagFactory extends Factory
{
    protected $model = ConversationTag::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => '#176b4d',
            'is_active' => true,
        ];
    }
}
