<?php

namespace Database\Seeders;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\MessageTemplateStatus;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MessageDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $user = User::query()->first();

        $template = MessageTemplate::query()->firstOrCreate(
            ['slug' => 'primeiro-contato-demo'],
            [
                'name' => 'Primeiro contato demo',
                'description' => 'Modelo de demonstração para desenvolvimento.',
                'body' => "Oi {primeiro_nome}, como esta {cidade}?\n\nSou o professor Felipe. Posso lhe fazer uma pergunta?",
                'status' => MessageTemplateStatus::Active,
                'version' => 1,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]
        );

        MessageTemplateVersion::query()->firstOrCreate(
            ['message_template_id' => $template->id, 'version' => 1],
            [
                'name' => $template->name,
                'description' => $template->description,
                'body' => $template->body,
                'placeholders' => ['primeiro_nome', 'cidade'],
                'created_by' => $user?->id,
            ]
        );

        foreach (['Ana Teste' => 'Lages', 'Bruno Exemplo' => 'Florianopolis', 'Carla Demo' => null] as $name => $city) {
            Contact::query()->firstOrCreate(
                ['email' => Str::slug($name).'@example.com'],
                [
                    'name' => $name,
                    'first_name' => Str::before($name, ' '),
                    'phone' => '(49) 99999-'.fake()->unique()->numerify('####'),
                    'phone_normalized' => '55499'.fake()->unique()->numerify('########'),
                    'city' => $city,
                    'state' => 'SC',
                    'country' => 'BR',
                    'status' => ContactStatus::Active,
                    'source' => ContactSource::Manual,
                    'consent_status' => ConsentStatus::NotInformed,
                    'created_by' => $user?->id,
                ]
            );
        }
    }
}
