<?php

namespace Tests\Unit\Mcp;

use App\Models\User;
use App\Services\Mcp\Knowledge\MintlifyKnowledgeSync;
use App\Services\Mcp\McpRequestAdapter;
use App\Services\Mcp\McpResultNormalizer;
use App\Services\Mcp\Presenters\AgentPerformancePresenter;
use Tests\TestCase;

class McpResultPresentationTest extends TestCase
{
    public function test_revenue_contract_money_is_rounded_without_changing_non_money_metrics(): void
    {
        $payload = (new McpResultNormalizer)->forTool('exotic_revenue_summary', [
            'total' => 13924.819999999999,
            'source_breakdown' => ['KES' => 19.999999999999],
            'rate' => 7.777777777777,
        ]);

        $this->assertSame(13924.82, $payload['total']);
        $this->assertSame(20.0, $payload['source_breakdown']['KES']);
        $this->assertSame(7.777777777777, $payload['rate']);
    }

    public function test_agent_performance_uses_staff_names_without_internal_ids_for_admins(): void
    {
        $admin = new User(['role' => 'admin']);
        $payload = (new AgentPerformancePresenter)->present(['agents' => [['id' => 18, 'name' => 'Jane Agent', 'role' => 'sales']]], $admin);

        $this->assertSame('Jane Agent', $payload['agents'][0]['agent_display_name']);
        $this->assertArrayNotHasKey('id', $payload['agents'][0]);
        $this->assertArrayNotHasKey('name', $payload['agents'][0]);
    }

    public function test_limit_is_forwarded_to_the_dashboard_query(): void
    {
        $request = (new McpRequestAdapter)->build(['limit' => 3], new User);

        $this->assertSame(3, $request->query('limit'));
    }

    public function test_knowledge_chunks_do_not_split_utf8_characters(): void
    {
        $method = new \ReflectionMethod(MintlifyKnowledgeSync::class, 'chunks');
        $chunks = $method->invoke(new MintlifyKnowledgeSync, str_repeat('€', 4501));

        $this->assertSame(2, count($chunks));
        $this->assertSame(1, preg_match('//u', $chunks[0]));
        $this->assertSame(1, preg_match('//u', $chunks[1]));
    }

    public function test_knowledge_manifest_parser_selects_only_approved_markdown_documents(): void
    {
        $method = new \ReflectionMethod(MintlifyKnowledgeSync::class, 'eligibleSlugs');
        $manifest = implode("\n", [
            'https://exoticonline.mintlify.app/payments/overview.md',
            'https://exoticonline.mintlify.app/payments/matching.md',
            'https://exoticonline.mintlify.app/unapproved/page.md',
            'https://example.test/product/overview.md',
        ]);

        $slugs = $method->invoke(new MintlifyKnowledgeSync, $manifest);

        $this->assertSame(['payments/overview.md', 'payments/matching.md'], $slugs);
    }

    public function test_knowledge_document_map_uses_the_literal_markdown_slug(): void
    {
        $method = new \ReflectionMethod(MintlifyKnowledgeSync::class, 'documentMeta');
        $meta = $method->invoke(new MintlifyKnowledgeSync, [
            'payments/overview.md' => ['uri' => 'exotic://docs/payments/overview'],
        ], 'payments/overview.md');

        $this->assertSame('exotic://docs/payments/overview', $meta['uri']);
    }
}
