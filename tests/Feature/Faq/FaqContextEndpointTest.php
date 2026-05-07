<?php

namespace Tests\Feature\Faq;

use App\Models\Faq\Article;
use App\Models\Faq\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqContextEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_endpoint_returns_scripts_before_runbooks_and_extracts_only_safe_snippets(): void
    {
        $sales = $this->userForRole('sales');
        Sanctum::actingAs($sales);

        $scriptCategory = Category::factory()->create([
            'slug' => 'sales-scripts',
            'name' => 'Sales Scripts',
            'crm_page' => null,
        ]);
        $paymentsCategory = Category::factory()->create([
            'slug' => 'payments',
            'name' => 'Payments',
            'crm_page' => 'payments',
        ]);

        $scriptArticle = Article::factory()->create([
            'category_id' => $scriptCategory->id,
            'slug' => 'existing-user-payment-methods',
            'title' => 'Existing user: payment methods available',
            'summary' => 'Wallet-first guidance',
            'body' => <<<'MD'
# Existing user: payment methods available

## Quick reply
Hi [Name], wallet is the fastest option because it is easier to track.

## Agent note
Only say this after you confirm the profile context.

## Policy line
Keep manual as fallback.
MD,
            'status' => 'published',
        ]);
        $scriptArticle->contexts()->create([
            'crm_page' => 'payments',
            'surface' => 'help_drawer',
            'context_kind' => 'script',
            'priority' => 10,
        ]);
        $scriptArticle->contexts()->create([
            'crm_page' => 'client_detail',
            'surface' => 'help_drawer',
            'context_kind' => 'script',
            'priority' => 20,
        ]);

        $explicitRunbook = Article::factory()->create([
            'category_id' => $paymentsCategory->id,
            'slug' => 'payment-diagnostics',
            'title' => 'Payment diagnostics',
            'summary' => 'wallet checks',
            'body' => "# Payment diagnostics\n\nUse wallet evidence and provider checks before retrying.",
            'status' => 'published',
        ]);
        $explicitRunbook->contexts()->create([
            'crm_page' => 'payments',
            'surface' => 'help_drawer',
            'context_kind' => 'runbook',
            'priority' => 5,
        ]);

        $categoryRunbook = Article::factory()->create([
            'category_id' => $paymentsCategory->id,
            'slug' => 'payment-link-follow-up',
            'title' => 'Payment link follow up',
            'summary' => 'wallet reminder',
            'body' => "# Payment link follow up\n\nRemind the client to complete wallet or checkout cleanly.",
            'status' => 'published',
        ]);

        Article::factory()->create([
            'category_id' => $scriptCategory->id,
            'slug' => 'draft-script',
            'title' => 'Draft Script',
            'body' => "# Draft Script\n\n## Quick reply\nDraft text",
            'status' => 'draft',
        ])->contexts()->create([
            'crm_page' => 'payments',
            'surface' => 'help_drawer',
            'context_kind' => 'script',
            'priority' => 99,
        ]);

        $response = $this->getJson('/api/crm/faq/context?crm_page=payments&search=wallet');

        $response->assertOk()
            ->assertJsonPath('scripts.0.slug', 'existing-user-payment-methods')
            ->assertJsonPath('scripts.0.snippets.0.label', 'Quick reply')
            ->assertJsonPath('scripts.0.snippets.0.copy_text', 'Hi [Name], wallet is the fastest option because it is easier to track.')
            ->assertJsonPath('runbooks.0.slug', 'payment-diagnostics')
            ->assertJsonPath('runbooks.1.slug', 'payment-link-follow-up')
            ->assertJsonPath('meta.scripts_count', 1)
            ->assertJsonPath('meta.runbooks_count', 2);

        $this->assertSame(1, collect($response->json('runbooks'))->where('slug', 'payment-diagnostics')->count());
        $this->assertStringNotContainsString('Agent note', json_encode($response->json('scripts.0.snippets')));
        $this->assertStringNotContainsString('Policy line', json_encode($response->json('scripts.0.snippets')));
        $this->assertNotNull($response->json('search_log_id'));

        $clientDetailResponse = $this->getJson('/api/crm/faq/context?crm_page=client_detail');
        $clientDetailResponse->assertOk()
            ->assertJsonPath('scripts.0.slug', 'existing-user-payment-methods');
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
