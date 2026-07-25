<?php

namespace Database\Factories;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'first_name' => str($name)->before(' ')->toString(),
            'phone' => '(49) 9'.fake()->numerify('####-####'),
            'phone_normalized' => '55499'.fake()->unique()->numerify('########'),
            'email' => fake()->safeEmail(),
            'city' => fake()->randomElement(['Lages', 'Florianopolis', 'Brasilia']),
            'state' => fake()->randomElement(['SC', 'DF']),
            'country' => 'BR',
            'status' => ContactStatus::Active,
            'source' => ContactSource::Manual,
            'consent_status' => ConsentStatus::NotInformed,
            'do_not_contact' => false,
            'created_by' => User::factory(),
        ];
    }
}
