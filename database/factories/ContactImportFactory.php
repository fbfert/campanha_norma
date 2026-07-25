<?php

namespace Database\Factories;

use App\Enums\ContactImportStatus;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContactImport> */
class ContactImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => 'contatos.csv',
            'stored_filename' => 'contact-imports/'.fake()->uuid().'.csv',
            'status' => ContactImportStatus::Uploaded,
            'options' => ['duplicate_strategy' => 'ignore'],
        ];
    }
}
