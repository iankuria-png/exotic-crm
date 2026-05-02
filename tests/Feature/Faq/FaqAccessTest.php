<?php

namespace Tests\Feature\Faq;

use App\Models\Faq\Article;
use App\Models\Faq\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_marketing_admin_and_sub_admin_can_read_faq_content(): void
    {
        $category = Category::factory()->create([
            'slug' => 'clients',
            'name' => 'Clients',
            'crm_page' => 'clients',
        ]);

        $article = Article::factory()->create([
            'category_id' => $category->id,
            'slug' => 'client-status-taxonomy',
            'title' => 'Client status taxonomy',
            'status' => 'published',
        ]);

        foreach (['sales', 'marketing', 'admin', 'sub_admin'] as $role) {
            $user = $this->userForRole($role);
            Sanctum::actingAs($user);

            $this->getJson('/api/crm/faq/categories?include_articles=1')
                ->assertOk()
                ->assertJsonPath('categories.0.slug', 'clients');

            $this->getJson('/api/crm/faq/articles')
                ->assertOk()
                ->assertJsonPath('articles.0.slug', $article->slug);

            $this->getJson('/api/crm/faq/articles/' . $article->slug)
                ->assertOk()
                ->assertJsonPath('article.slug', $article->slug);
        }
    }

    public function test_only_admin_and_sub_admin_can_write_faq_content(): void
    {
        foreach (['sales', 'marketing'] as $role) {
            Sanctum::actingAs($this->userForRole($role));

            $this->postJson('/api/crm/faq/categories', [
                'slug' => 'forbidden-category-' . $role,
                'name' => 'Forbidden',
            ])->assertStatus(403);
        }

        foreach (['admin', 'sub_admin'] as $role) {
            Sanctum::actingAs($this->userForRole($role));

            $this->postJson('/api/crm/faq/categories', [
                'slug' => 'allowed-category-' . $role,
                'name' => 'Allowed',
                'crm_page' => 'dashboard',
            ])->assertCreated();
        }
    }

    private function userForRole(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
