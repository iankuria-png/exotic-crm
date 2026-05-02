<?php

namespace Tests\Feature\Faq;

use App\Models\AuditLog;
use App\Models\Faq\Article;
use App\Models\Faq\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqArticleCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_publish_search_and_audit_faq_article(): void
    {
        $admin = $this->userForRole('admin');
        $category = Category::factory()->create([
            'slug' => 'payments',
            'crm_page' => 'payments',
        ]);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/crm/faq/articles', [
            'category_id' => $category->id,
            'slug' => 'untracked-payment-reconciliation',
            'title' => 'Reconciling Untracked payments',
            'summary' => 'How to work the untracked queue safely.',
            'body' => "# Reconciling Untracked payments\n\nUse the queue to find orphaned payment records.",
            'status' => 'draft',
            'ctas' => [
                [
                    'kind' => 'deep_link',
                    'label' => 'Open Payments Queue',
                    'target_path' => '/payments?tab=queue',
                    'position' => 1,
                ],
            ],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('article.status', 'draft')
            ->assertJsonPath('article.ctas.0.label', 'Open Payments Queue');

        $articleId = (int) $createResponse->json('article.id');
        $article = Article::query()->findOrFail($articleId);

        $this->patchJson('/api/crm/faq/articles/' . $article->slug . '/draft', [
            'title' => 'Reconciling Untracked payments',
            'summary' => 'How to work the untracked queue safely.',
            'body_draft' => "# Reconciling Untracked payments\n\nSQLite search fallback should still find this text.",
        ])->assertOk();

        $this->postJson('/api/crm/faq/articles/' . $article->slug . '/publish')
            ->assertOk()
            ->assertJsonPath('article.status', 'published');

        $searchResponse = $this->getJson('/api/crm/faq/articles?search=SQLite%20fallback');
        $searchResponse->assertOk()
            ->assertJsonPath('articles.0.slug', $article->slug)
            ->assertJsonPath('search_log_id', 1);

        $auditRows = AuditLog::query()
            ->where('entity_type', 'faq_article')
            ->where('entity_id', $articleId)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(3, $auditRows->count());
        $this->assertTrue($auditRows->every(fn (AuditLog $log) => $log->platform_id === null));
    }

    public function test_admin_can_upload_media_and_validation_rejects_invalid_mime(): void
    {
        Storage::fake('public');

        $admin = $this->userForRole('admin');
        $article = Article::factory()->create([
            'status' => 'published',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/faq/articles/' . $article->slug . '/media', [
            'file' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);

        $response = $this->post('/api/crm/faq/articles/' . $article->slug . '/media', [
            'file' => UploadedFile::fake()->image('diagram.png'),
            'caption' => 'Queue diagram',
        ]);

        $response->assertCreated();
        Storage::disk('public')->assertExists('faq/' . $article->id . '/' . basename($response->json('media.disk_path')));
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
