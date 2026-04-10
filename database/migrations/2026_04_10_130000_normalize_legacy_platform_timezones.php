<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $legacyAliases = [
        'Africa/Yamoussoukro' => 'Africa/Abidjan',
    ];

    public function up(): void
    {
        foreach ($this->legacyAliases as $legacy => $canonical) {
            DB::table('platforms')
                ->whereRaw('LOWER(timezone) = ?', [strtolower($legacy)])
                ->update(['timezone' => $canonical]);
        }
    }

    public function down(): void
    {
        // Intentionally left as a no-op because timezone normalization is a data repair.
    }
};
