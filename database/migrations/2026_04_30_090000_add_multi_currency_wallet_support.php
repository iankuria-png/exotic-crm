<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            if (!Schema::hasColumn('platforms', 'supported_currencies')) {
                $table->json('supported_currencies')->nullable()->after('currency_code');
            }

            if (!Schema::hasColumn('platforms', 'multi_currency_wallet_enabled')) {
                $table->boolean('multi_currency_wallet_enabled')->default(false)->after('supported_currencies');
            }
        });

        if (Schema::hasTable('platforms') && Schema::hasColumn('platforms', 'supported_currencies')) {
            DB::table('platforms')
                ->select('id', 'currency_code', 'supported_currencies')
                ->orderBy('id')
                ->get()
                ->each(function ($platform): void {
                    if ($platform->supported_currencies !== null) {
                        return;
                    }

                    $currency = strtoupper(trim((string) ($platform->currency_code ?? 'KES')));
                    DB::table('platforms')
                        ->where('id', (int) $platform->id)
                        ->update([
                            'supported_currencies' => json_encode([$currency !== '' ? $currency : 'KES']),
                        ]);
                });
        }

        if (Schema::hasTable('product_prices')) {
            if ($this->indexExists('product_prices', 'product_prices_product_duration_unique')) {
                Schema::table('product_prices', function (Blueprint $table): void {
                    $table->dropUnique('product_prices_product_duration_unique');
                });
            }

            if (!$this->indexExists('product_prices', 'product_prices_product_duration_currency_unique')) {
                Schema::table('product_prices', function (Blueprint $table): void {
                    $table->unique(
                        ['product_id', 'duration_key', 'currency'],
                        'product_prices_product_duration_currency_unique'
                    );
                });
            }
        }

        Schema::table('billing_wallet_rules', function (Blueprint $table): void {
            if (!Schema::hasColumn('billing_wallet_rules', 'supported_currencies_json')) {
                $table->json('supported_currencies_json')->nullable()->after('currency_code');
            }

            if (!Schema::hasColumn('billing_wallet_rules', 'topup_preset_by_currency_json')) {
                $table->json('topup_preset_by_currency_json')->nullable()->after('topup_preset_json');
            }

            if (!Schema::hasColumn('billing_wallet_rules', 'limit_by_currency_json')) {
                $table->json('limit_by_currency_json')->nullable()->after('limit_json');
            }
        });

        if (!Schema::hasTable('client_wallet_balances')) {
            Schema::create('client_wallet_balances', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('client_id');
                $table->string('currency', 3);
                $table->decimal('balance', 12, 2)->default(0);
                $table->dateTime('last_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['client_id', 'currency'], 'client_wallet_balances_client_currency_unique');
                $table->index(['client_id', 'updated_at'], 'client_wallet_balances_client_updated_idx');
                $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('clients') && Schema::hasTable('client_wallet_balances')) {
            $now = now();
            DB::table('clients')
                ->select('id', 'wallet_balance', 'wallet_currency', 'wallet_last_synced_at')
                ->chunkById(500, function ($clients) use ($now): void {
                    $rows = $clients
                        ->map(function ($client) use ($now): array {
                            $currency = strtoupper(trim((string) ($client->wallet_currency ?? '')));

                            return [
                                'client_id' => (int) $client->id,
                                'currency' => $currency !== '' ? $currency : 'KES',
                                'balance' => number_format((float) ($client->wallet_balance ?? 0), 2, '.', ''),
                                'last_synced_at' => $client->wallet_last_synced_at,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        })
                        ->all();

                    if ($rows !== []) {
                        DB::table('client_wallet_balances')->insertOrIgnore($rows);
                    }
                }, 'id');
        }

        if (Schema::hasTable('wallet_transactions')) {
            if ($this->indexExists('wallet_transactions', 'wallet_transactions_idempotency_key_unique')) {
                Schema::table('wallet_transactions', function (Blueprint $table): void {
                    $table->dropUnique('wallet_transactions_idempotency_key_unique');
                });
            }

            if (!$this->indexExists('wallet_transactions', 'wallet_tx_client_currency_idempotency_unique')) {
                Schema::table('wallet_transactions', function (Blueprint $table): void {
                    $table->unique(
                        ['client_id', 'currency_code', 'idempotency_key'],
                        'wallet_tx_client_currency_idempotency_unique'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wallet_transactions')) {
            if ($this->indexExists('wallet_transactions', 'wallet_tx_client_currency_idempotency_unique')) {
                Schema::table('wallet_transactions', function (Blueprint $table): void {
                    $table->dropUnique('wallet_tx_client_currency_idempotency_unique');
                });
            }

            if (!$this->indexExists('wallet_transactions', 'wallet_transactions_idempotency_key_unique')) {
                Schema::table('wallet_transactions', function (Blueprint $table): void {
                    $table->unique('idempotency_key', 'wallet_transactions_idempotency_key_unique');
                });
            }
        }

        Schema::dropIfExists('client_wallet_balances');

        Schema::table('billing_wallet_rules', function (Blueprint $table): void {
            foreach ([
                'supported_currencies_json',
                'topup_preset_by_currency_json',
                'limit_by_currency_json',
            ] as $column) {
                if (Schema::hasColumn('billing_wallet_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('product_prices')) {
            if ($this->indexExists('product_prices', 'product_prices_product_duration_currency_unique')) {
                Schema::table('product_prices', function (Blueprint $table): void {
                    $table->dropUnique('product_prices_product_duration_currency_unique');
                });
            }

            if (!$this->indexExists('product_prices', 'product_prices_product_duration_unique')) {
                Schema::table('product_prices', function (Blueprint $table): void {
                    $table->unique(['product_id', 'duration_key'], 'product_prices_product_duration_unique');
                });
            }
        }

        Schema::table('platforms', function (Blueprint $table): void {
            foreach (['supported_currencies', 'multi_currency_wallet_enabled'] as $column) {
                if (Schema::hasColumn('platforms', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => (string) ($row->name ?? '') === $index),
            'pgsql' => collect(DB::select(
                'select indexname from pg_indexes where schemaname = current_schema() and tablename = ?',
                [$table]
            ))->contains(fn ($row) => (string) ($row->indexname ?? '') === $index),
            default => collect(DB::select("SHOW INDEX FROM `{$table}`"))
                ->contains(fn ($row) => (string) ($row->Key_name ?? '') === $index),
        };
    }
};
