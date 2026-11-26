<?php

namespace Tests\Feature\Kyc;

use App\Models\KycAiReview;
use App\Services\Kyc\Ai\KycVisionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;
use Tests\Unit\Kyc\KycAiPolicyTest;

class VisionClientTest extends TestCase
{
    use InteractsWithKycFixtures, RefreshDatabase;

    public function test_private_image_request_uses_strict_schema_and_zero_retention(): void
    {
        config(['services.seo_engine.openrouter.api_key' => 'synthetic-test-key']);
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client, ['document_type' => 'passport']);
        $image = imagecreatetruecolor(100, 100);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $document = $this->createDbDocument($subject, 'id_front', $bytes, 'image/png');
        $review = KycAiReview::create(['subject_id' => $subject->id, 'document_ids' => [$document->id], 'review_version' => 0, 'mode' => 'shadow']);
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(KycAiPolicyTest::clean())]]], 'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 100, 'cost' => .001]])]);
        $result = app(KycVisionClient::class)->inspect($review, 'google/gemini-3.8-flash');
        $this->assertSame('1234', $result['document_number_last4']);
        Http::assertSent(function ($request) {
            $this->assertSame(4000, $request['max_tokens']);
            $this->assertContains('evaluation_confidence', $request['response_format']['json_schema']['schema']['required']);
            $this->assertTrue($request['provider']['zdr']);
            $this->assertSame('deny', $request['provider']['data_collection']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);
            $url = $request['messages'][1]['content'][2]['image_url']['url'];
            $this->assertStringStartsWith('data:image/jpeg;base64,', $url);
            $this->assertSame('image/jpeg', getimagesizefromstring(base64_decode(explode(',', $url, 2)[1]))['mime']);

            return true;
        });
        $this->assertEquals(.001, $review->fresh()->cost_usd);
    }

    public function test_nonfinal_responses_record_only_safe_finish_diagnostics(): void
    {
        config(['services.seo_engine.openrouter.api_key' => 'synthetic-test-key']);
        $subject = $this->createSubjectForClient($this->createClientForPlatform($this->createPlatform()));
        $review = KycAiReview::create(['subject_id' => $subject->id, 'document_ids' => [], 'review_version' => 0, 'mode' => 'shadow']);
        $finish = 'length';
        Http::fake(function () use (&$finish) {
            return Http::response(['choices' => [['finish_reason' => $finish, 'message' => ['content' => 'PRIVATE-SYNTHETIC-CONTENT']]],
                'usage' => ['completion_tokens' => 2000, 'completion_tokens_details' => ['reasoning_tokens' => 1900]]]);
        });
        foreach (['length' => 'provider_output_truncated', 'content_filter' => 'model_declined', 'error' => 'incomplete_response'] as $finish => $reason) {
            try {
                app(KycVisionClient::class)->inspect($review, 'google/gemini-3.8-flash');
                $this->fail('Incomplete response accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame($reason, $e->getMessage());
            }
        }
        $events = $subject->reviewEvents()->where('event', 'provider_response_received')->get();
        $this->assertCount(3, $events);
        $this->assertSame(1900, $events->first()->metadata['reasoning_tokens']);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC-CONTENT', $events->toJson());
    }
}
