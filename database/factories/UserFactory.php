<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the user has completed onboarding.
     */
    public function onboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'city' => 'Köln',
            'veedel' => fake()->randomElement(['Ehrenfeld', 'Nippes', 'Sülz', 'Deutz', 'Neustadt-Nord']),
            'situation' => fake()->randomElement(['non_eu_employee', 'eu_employee', 'student', 'freelancer']),
            'is_eu' => fake()->boolean(),
            'arrival_date' => fake()->dateTimeBetween('-2 years', 'now'),
            'german_level' => fake()->randomElement(['none', 'a1', 'a2', 'b1', 'b2']),
            'onboarded_at' => now(),
        ]);
    }

    /**
     * Indicate that the user has not completed onboarding.
     */
    public function notOnboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'city' => null,
            'veedel' => null,
            'situation' => null,
            'is_eu' => null,
            'arrival_date' => null,
            'german_level' => null,
            'onboarded_at' => null,
        ]);
    }
}
