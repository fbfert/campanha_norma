<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContactDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $user = User::query()->first();

        $tag = Tag::query()->firstOrCreate(
            ['slug' => 'teste'],
            ['name' => 'Teste', 'color' => '#176b4d', 'is_active' => true, 'created_by' => $user?->id]
        );

        Contact::factory()->count(8)->create(['created_by' => $user?->id])->each(fn (Contact $contact) => $contact->tags()->syncWithoutDetaching([$tag->id => ['created_by' => $user?->id]]));
    }
}
