<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_provider_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_provider_transactions', 'retry_of_provider_transaction_id')) {
                $table->foreignId('retry_of_provider_transaction_id')->nullable()->after('attempt_sequence');
            }

            if (! Schema::hasColumn('billing_provider_transactions', 'fallback_from_provider_transaction_id')) {
                $table->foreignId('fallback_from_provider_transaction_id')->nullable()->after('retry_of_provider_transaction_id');
            }
        });

        Schema::table('billing_provider_transactions', function (Blueprint $table) {
            $foreignNames = $this->foreignNames();

            if (! in_array('billing_tx_retry_fk', $foreignNames, true)) {
                $table->foreign('retry_of_provider_transaction_id')
                    ->references('id')
                    ->on('billing_provider_transactions')
                    ->nullOnDelete()
                    ->name('billing_tx_retry_fk');
            }

            if (! in_array('billing_tx_fallback_fk', $foreignNames, true)) {
                $table->foreign('fallback_from_provider_transaction_id')
                    ->references('id')
                    ->on('billing_provider_transactions')
                    ->nullOnDelete()
                    ->name('billing_tx_fallback_fk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_provider_transactions', function (Blueprint $table) {
            $foreignNames = $this->foreignNames();

            if (in_array('billing_tx_retry_fk', $foreignNames, true)) {
                $table->dropForeign('billing_tx_retry_fk');
            }

            if (in_array('billing_tx_fallback_fk', $foreignNames, true)) {
                $table->dropForeign('billing_tx_fallback_fk');
            }

            $columnsToDrop = array_values(array_filter([
                Schema::hasColumn('billing_provider_transactions', 'retry_of_provider_transaction_id')
                    ? 'retry_of_provider_transaction_id'
                    : null,
                Schema::hasColumn('billing_provider_transactions', 'fallback_from_provider_transaction_id')
                    ? 'fallback_from_provider_transaction_id'
                    : null,
            ]));

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    private function foreignNames(): array
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA foreign_key_list('billing_provider_transactions')"))
                ->pluck('from')
                ->map(function (string $column): string {
                    return match ($column) {
                        'retry_of_provider_transaction_id' => 'billing_tx_retry_fk',
                        'fallback_from_provider_transaction_id' => 'billing_tx_fallback_fk',
                        default => strtolower($column),
                    };
                })
                ->all();
        }

        if ($driver === 'mysql') {
            return collect(DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'billing_provider_transactions'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            "))
                ->pluck('CONSTRAINT_NAME')
                ->map(static fn (string $name): string => strtolower($name))
                ->all();
        }

        return [];
    }
};
