<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('deals', 'origin')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE deals MODIFY origin VARCHAR(50) NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('deals', 'origin')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::table('deals')
                ->whereRaw('CHAR_LENGTH(origin) > 20')
                ->update(['origin' => 'manual_bundle']);

            DB::statement("ALTER TABLE deals MODIFY origin VARCHAR(20) NOT NULL DEFAULT 'manual'");
        }
    }
};
