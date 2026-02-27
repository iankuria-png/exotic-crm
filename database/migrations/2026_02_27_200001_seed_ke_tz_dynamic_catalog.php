<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_prices') || !Schema::hasTable('products')) {
            return;
        }

        $this->seedMarketCatalog(
            marketNamePattern: '%kenya%',
            fallbackCountryPattern: '%kenya%',
            currency: 'KES',
            catalog: [
                ['name' => 'VVIP', 'tier' => 'vvip', 'sort' => 10, 'prices' => [
                    ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'price' => 12000],
                ]],
                ['name' => 'VIP', 'tier' => 'vip', 'sort' => 20, 'prices' => [
                    ['key' => '2_weeks', 'label' => '2 Weeks', 'days' => 14, 'price' => 3000],
                    ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'price' => 6000],
                ]],
                ['name' => 'PREMIUM', 'tier' => 'premium', 'sort' => 30, 'prices' => [
                    ['key' => '2_weeks', 'label' => '2 Weeks', 'days' => 14, 'price' => 2000],
                    ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'price' => 4000],
                ]],
                ['name' => 'BASIC', 'tier' => 'basic', 'sort' => 40, 'prices' => [
                    ['key' => '2_weeks', 'label' => '2 Weeks', 'days' => 14, 'price' => 1500],
                    ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'price' => 3000],
                ]],
            ]
        );

        $this->seedMarketCatalog(
            marketNamePattern: '%tanzania%',
            fallbackCountryPattern: '%tanzania%',
            currency: 'TZS',
            catalog: [
                ['name' => 'VVIP', 'tier' => 'vvip', 'sort' => 10, 'prices' => [
                    ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'price' => 200000],
                ]],
                ['name' => 'VIP', 'tier' => 'vip', 'sort' => 20, 'prices' => [
                    ['key' => '1_week', 'label' => '1 Week', 'days' => 7, 'price' => 35000],
                    ['key' => '2_weeks', 'label' => '2 Weeks', 'days' => 14, 'price' => 70000],
                ]],
                ['name' => 'PREMIUM', 'tier' => 'premium', 'sort' => 30, 'prices' => [
                    ['key' => '2_weeks', 'label' => '2 Weeks', 'days' => 14, 'price' => 40000],
                    ['key' => '1_month', 'label' => '1 Month', 'days' => 30, 'price' => 80000],
                ]],
            ],
            paymentInstruction: '+255746734025 AMANI MOLLEL'
        );
    }

    private function seedMarketCatalog(
        string $marketNamePattern,
        string $fallbackCountryPattern,
        string $currency,
        array $catalog,
        ?string $paymentInstruction = null
    ): void {
        $platform = DB::table('platforms')
            ->select('id', 'currency_code')
            ->where(function ($query) use ($marketNamePattern, $fallbackCountryPattern): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$marketNamePattern])
                    ->orWhereRaw('LOWER(country) LIKE ?', [$fallbackCountryPattern]);
            })
            ->orderBy('id')
            ->first();

        if (!$platform) {
            return;
        }

        $platformId = (int) $platform->id;
        $now = now();

        foreach ($catalog as $packageDef) {
            $name = $packageDef['name'];
            $slug = Str::slug($name, '_');

            // Find or create the product
            $product = DB::table('products')
                ->where('platform_id', $platformId)
                ->whereRaw('UPPER(name) = ?', [$name])
                ->first();

            if ($product) {
                DB::table('products')->where('id', $product->id)->update([
                    'display_name' => Str::title(strtolower($name)),
                    'slug' => $slug,
                    'tier' => $packageDef['tier'],
                    'sort_order' => $packageDef['sort'],
                    'currency' => $currency,
                    'is_active' => true,
                    'is_archived' => false,
                    'updated_at' => $now,
                ]);
                $productId = (int) $product->id;
            } else {
                $productId = (int) DB::table('products')->insertGetId([
                    'platform_id' => $platformId,
                    'name' => $name,
                    'display_name' => Str::title(strtolower($name)),
                    'slug' => $slug,
                    'tier' => $packageDef['tier'],
                    'sort_order' => $packageDef['sort'],
                    'weekly_price' => 0,
                    'biweekly_price' => 0,
                    'monthly_price' => 0,
                    'currency' => $currency,
                    'is_active' => true,
                    'is_archived' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Deactivate any existing price rows for this product
            DB::table('product_prices')
                ->where('product_id', $productId)
                ->update(['is_active' => false]);

            // Insert/update the canonical price rows
            $legacyUpdates = ['weekly_price' => 0, 'biweekly_price' => 0, 'monthly_price' => 0];
            $legacyMap = ['1_week' => 'weekly_price', '2_weeks' => 'biweekly_price', '1_month' => 'monthly_price'];

            foreach ($packageDef['prices'] as $priceDef) {
                $existing = DB::table('product_prices')
                    ->where('product_id', $productId)
                    ->where('duration_key', $priceDef['key'])
                    ->first();

                if ($existing) {
                    DB::table('product_prices')->where('id', $existing->id)->update([
                        'duration_label' => $priceDef['label'],
                        'duration_days' => $priceDef['days'],
                        'price' => $priceDef['price'],
                        'currency' => $currency,
                        'is_active' => true,
                        'sort_order' => $priceDef['days'],
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('product_prices')->insert([
                        'product_id' => $productId,
                        'duration_key' => $priceDef['key'],
                        'duration_label' => $priceDef['label'],
                        'duration_days' => $priceDef['days'],
                        'price' => $priceDef['price'],
                        'currency' => $currency,
                        'is_active' => true,
                        'sort_order' => $priceDef['days'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (isset($legacyMap[$priceDef['key']])) {
                    $legacyUpdates[$legacyMap[$priceDef['key']]] = $priceDef['price'];
                }
            }

            // Sync legacy price columns
            DB::table('products')->where('id', $productId)->update($legacyUpdates);
        }

        // Archive any products for this platform that are not in the catalog
        $catalogNames = array_map(fn($p) => $p['name'], $catalog);
        DB::table('products')
            ->where('platform_id', $platformId)
            ->whereNotIn(DB::raw('UPPER(name)'), $catalogNames)
            ->update(['is_active' => false, 'is_archived' => true]);

        // Set payment instruction if provided
        if ($paymentInstruction && Schema::hasColumn('platforms', 'payment_instruction')) {
            DB::table('platforms')
                ->where('id', $platformId)
                ->update(['payment_instruction' => $paymentInstruction]);
        }
    }

    public function down(): void
    {
        // No rollback — catalog data is managed via admin UI going forward
    }
};
