<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('region')->nullable()->after('city');
        });

        Schema::table('platforms', function (Blueprint $table): void {
            $table->unsignedInteger('wp_currency_id')->nullable()->after('currency_code');
        });

        DB::table('platforms')
            ->select(['id', 'country', 'currency_code'])
            ->orderBy('id')
            ->get()
            ->each(function ($platform): void {
                $country = strtolower(trim((string) ($platform->country ?? '')));
                $currency = strtoupper(trim((string) ($platform->currency_code ?? '')));

                $defaultCurrencyId = match (true) {
                    $country === 'kenya', $currency === 'KES' => 50,
                    $country === 'tanzania', $currency === 'TZS' => 72,
                    $country === 'uganda', $currency === 'UGX' => 76,
                    default => null,
                };

                if ($defaultCurrencyId === null) {
                    return;
                }

                DB::table('platforms')
                    ->where('id', (int) $platform->id)
                    ->update(['wp_currency_id' => $defaultCurrencyId]);
            });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->dropColumn('wp_currency_id');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('region');
        });
    }
};
