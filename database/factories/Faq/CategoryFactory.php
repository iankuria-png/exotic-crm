<?php

namespace Database\Factories\Faq;

use App\Models\Faq\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'slug' => Str::slug($name) . '-' . fake()->unique()->lexify('???'),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'crm_page' => fake()->randomElement(['dashboard', 'clients', 'client_detail', 'deals', 'payments', 'cross_cutting']),
            'position' => fake()->numberBetween(1, 20),
        ];
    }
}
