<?php

use App\Services\University\MintlifySeedService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MintlifySeedService::class)->seedDraftUniversity();
    }

    public function down(): void
    {
        // The starter University content is intentionally left in place.
    }
};
