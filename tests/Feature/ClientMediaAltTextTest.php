<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientMediaAltTextTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private Client $client;

    private string $mediaUrl;

    /** @var list<string> */
    private array $sentAltText = [];

    /**
     * Fake the WP plugin and capture the alt_text field off each upload.
     *
     * @param  list<array<string, mixed>>  $existingMedia
     */
    private function fakeMarket(array $existingMedia = []): void
    {
        $this->platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
            'country' => 'Kenya',
        ]);

        $this->client = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'wp_post_id' => 61783,
            'name' => 'Naima Yemeni',
            'city' => 'Westlands',
            'client_type' => 'escort',
        ]);

        $this->mediaUrl = rtrim($this->platform->wp_api_url, '/')."/clients/{$this->client->wp_post_id}/media";
        $mediaUrl = $this->mediaUrl;

        Http::fake(function ($request) use ($mediaUrl, $existingMedia) {
            if ($request->method() === 'GET' && $request->url() === $mediaUrl) {
                return Http::response(['data' => $existingMedia], 200);
            }

            if ($request->method() === 'POST' && $request->url() === $mediaUrl) {
                $this->sentAltText[] = $this->altTextFrom($request->body());

                return Http::response([
                    'attachment' => [
                        'id' => 62130,
                        'url' => 'https://www.exotickenya.com/wp-content/uploads/photo.jpg',
                        'mime_type' => 'image/jpeg',
                        'is_main' => false,
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });
    }

    /**
     * Pull the alt_text part out of a multipart request body.
     */
    private function altTextFrom(string $body): string
    {
        // Each part carries its own Content-Length header, so skip to the
        // blank line that separates the part headers from the value.
        if (preg_match('/name="alt_text".*?\r?\n\r?\n(.*?)\r?\n--/s', $body, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    private function actAsSales(): void
    {
        $user = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$this->platform->id],
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);
    }

    public function test_it_sends_generated_alt_text_with_an_image_upload(): void
    {
        $this->fakeMarket();
        $this->actAsSales();

        $this->post("/api/crm/clients/{$this->client->id}/media", [
            'files' => [UploadedFile::fake()->image('whatsapp-image.jpg')],
        ])->assertOk();

        $this->assertSame(['Naima Yemeni, escort in Westlands, Kenya'], $this->sentAltText);
    }

    public function test_it_numbers_alt_text_from_the_images_already_on_the_profile(): void
    {
        $this->fakeMarket([
            ['id' => 1, 'url' => 'https://www.exotickenya.com/a.jpg', 'mime_type' => 'image/jpeg'],
            ['id' => 2, 'url' => 'https://www.exotickenya.com/b.jpg', 'mime_type' => 'image/jpeg'],
            // A video must not consume an image position.
            ['id' => 3, 'url' => 'https://www.exotickenya.com/c.mp4', 'mime_type' => 'video/mp4'],
        ]);
        $this->actAsSales();

        $this->post("/api/crm/clients/{$this->client->id}/media", [
            'files' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ])->assertOk();

        $this->assertSame([
            'Naima Yemeni, escort in Westlands, Kenya (photo 3)',
            'Naima Yemeni, escort in Westlands, Kenya (photo 4)',
        ], $this->sentAltText);
    }

    public function test_it_sends_no_alt_text_for_a_video_upload(): void
    {
        $this->fakeMarket();
        $this->actAsSales();

        $this->post("/api/crm/clients/{$this->client->id}/media", [
            'files' => [UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4')],
        ])->assertOk();

        $this->assertSame([''], $this->sentAltText);
    }
}
