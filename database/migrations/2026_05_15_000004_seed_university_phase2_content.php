<?php

use App\Services\University\UniversityPhase2Seeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: the Phase 2 seeder upserts by slug/code, so re-running this
        // migration (or invoking `php artisan crm:seed-university`) will neither
        // duplicate rows nor overwrite admin-authored edits to course content.
        app(UniversityPhase2Seeder::class)->run();
    }

    public function down(): void
    {
        // Seed data is intentionally left in place — it represents the canonical
        // operating playbook and is safe to keep even if this migration is rolled
        // back. To wipe it explicitly, run a separate housekeeping command.
    }
};
