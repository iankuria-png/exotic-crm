<?php

namespace Database\Factories\Faq;

use App\Models\Faq\Article;
use App\Models\Faq\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = Str::title(fake()->words(4, true));

        return [
            'category_id' => Category::factory(),
            'slug' => Str::slug($title) . '-' . fake()->unique()->lexify('???'),
            'title' => $title,
            'summary' => fake()->sentence(),
            'body' => "# {$title}\n\n" . fake()->paragraphs(3, true),
            'body_draft' => null,
            'status' => 'published',
            'author_id' => User::factory(),
            'last_editor_id' => null,
            'position' => fake()->numberBetween(1, 50),
            'view_count' => fake()->numberBetween(0, 200),
            'helpful_count' => fake()->numberBetween(0, 40),
            'unhelpful_count' => fake()->numberBetween(0, 10),
            'published_at' => now(),
        ];
    }
}
