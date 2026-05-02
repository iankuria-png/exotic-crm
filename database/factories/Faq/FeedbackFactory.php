<?php

namespace Database\Factories\Faq;

use App\Models\Faq\Article;
use App\Models\Faq\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'user_id' => User::factory(),
            'kind' => fake()->randomElement(['bug', 'feature_request', 'general']),
            'helpful' => null,
            'title' => fake()->sentence(4),
            'comment' => fake()->paragraph(),
            'severity' => fake()->randomElement(['low', 'medium', 'high']),
            'context_path' => fake()->randomElement(['/payments', '/clients', '/faq']),
            'context_meta' => ['viewport' => '1440x900'],
            'status' => fake()->randomElement(['new', 'triaged', 'planned']),
            'status_changed_at' => now(),
            'last_seen_at' => null,
            'status_history' => [[
                'status' => 'new',
                'changed_at' => now()->toIso8601String(),
                'actor_id' => null,
                'note' => 'Created in factory',
            ]],
        ];
    }
}
