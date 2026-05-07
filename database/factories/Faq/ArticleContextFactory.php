<?php

namespace Database\Factories\Faq;

use App\Models\Faq\Article;
use App\Models\Faq\ArticleContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArticleContextFactory extends Factory
{
    protected $model = ArticleContext::class;

    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'crm_page' => fake()->randomElement(['clients', 'client_detail', 'payments', 'leads']),
            'surface' => 'help_drawer',
            'context_kind' => fake()->randomElement(['script', 'runbook']),
            'priority' => fake()->numberBetween(1, 50),
        ];
    }
}
