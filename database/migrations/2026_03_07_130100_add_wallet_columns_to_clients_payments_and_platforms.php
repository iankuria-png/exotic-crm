<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addClientWalletColumns();
        $this->addPaymentWalletColumns();
        $this->addPlatformWalletColumns();
        $this->backfillClientWalletCurrency();
    }

    public function down(): void
    {
        $this->dropPlatformWalletColumns();
        $this->dropPaymentWalletColumns();
        $this->dropClientWalletColumns();
    }

    private function addClientWalletColumns(): void
    {
        $needsWalletBalance = !Schema::hasColumn('clients', 'wallet_balance');
        $needsWalletCurrency = !Schema::hasColumn('clients', 'wallet_currency');
        $needsWalletLastSyncedAt = !Schema::hasColumn('clients', 'wallet_last_synced_at');

        if (!$needsWalletBalance && !$needsWalletCurrency && !$needsWalletLastSyncedAt) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use (
            $needsWalletBalance,
            $needsWalletCurrency,
            $needsWalletLastSyncedAt
        ) {
            if ($needsWalletBalance) {
                $table->decimal('wallet_balance', 12, 2)->default(0)->after('main_image_url');
            }

            if ($needsWalletCurrency) {
                $table->string('wallet_currency', 3)->default('KES')->after('wallet_balance');
            }

            if ($needsWalletLastSyncedAt) {
                $table->dateTime('wallet_last_synced_at')->nullable()->after('wallet_currency');
            }
        });
    }

    private function addPaymentWalletColumns(): void
    {
        $needsPurpose = !Schema::hasColumn('payments', 'purpose');
        $needsWalletTransactionId = !Schema::hasColumn('payments', 'wallet_transaction_id');
        $needsProviderKey = !Schema::hasColumn('payments', 'provider_key');
        $needsProviderEnvironment = !Schema::hasColumn('payments', 'provider_environment');

        if (!$needsPurpose && !$needsWalletTransactionId && !$needsProviderKey && !$needsProviderEnvironment) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) use (
            $needsPurpose,
            $needsWalletTransactionId,
            $needsProviderKey,
            $needsProviderEnvironment
        ) {
            if ($needsPurpose) {
                $table->string('purpose', 30)->default('subscription')->after('status');
                $table->index('purpose', 'payments_purpose_idx');
            }

            if ($needsWalletTransactionId) {
                $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('source');
                $table->index('wallet_transaction_id', 'payments_wallet_transaction_idx');
            }

            if ($needsProviderKey) {
                $table->string('provider_key', 30)->nullable()->after('wallet_transaction_id');
            }

            if ($needsProviderEnvironment) {
                $table->string('provider_environment', 20)->nullable()->after('provider_key');
                $table->index(['provider_key', 'provider_environment'], 'payments_provider_env_idx');
            }
        });

        if (DB::getDriverName() !== 'sqlite' && Schema::hasColumn('payments', 'wallet_transaction_id')) {
            $databaseName = DB::getDatabaseName();
            $constraintExists = DB::table('information_schema.table_constraints')
                ->where('table_schema', $databaseName)
                ->where('table_name', 'payments')
                ->where('constraint_name', 'payments_wallet_transaction_id_foreign')
                ->exists();

            if (!$constraintExists) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->foreign('wallet_transaction_id')
                        ->references('id')
                        ->on('wallet_transactions')
                        ->nullOnDelete();
                });
            }
        }
    }

    private function addPlatformWalletColumns(): void
    {
        if (Schema::hasColumn('platforms', 'wallet_settings')) {
            return;
        }

        Schema::table('platforms', function (Blueprint $table) {
            $table->json('wallet_settings')->nullable()->after('support_chat_url');
        });
    }

    private function backfillClientWalletCurrency(): void
    {
        if (!Schema::hasColumn('clients', 'wallet_currency')) {
            return;
        }

        DB::table('clients')->update([
            'wallet_currency' => DB::raw(
                "COALESCE((SELECT currency_code FROM platforms WHERE platforms.id = clients.platform_id), wallet_currency, 'KES')"
            ),
        ]);
    }

    private function dropPlatformWalletColumns(): void
    {
        if (!Schema::hasColumn('platforms', 'wallet_settings')) {
            return;
        }

        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn('wallet_settings');
        });
    }

    private function dropPaymentWalletColumns(): void
    {
        $hasPurpose = Schema::hasColumn('payments', 'purpose');
        $hasWalletTransactionId = Schema::hasColumn('payments', 'wallet_transaction_id');
        $hasProviderKey = Schema::hasColumn('payments', 'provider_key');
        $hasProviderEnvironment = Schema::hasColumn('payments', 'provider_environment');

        if (!$hasPurpose && !$hasWalletTransactionId && !$hasProviderKey && !$hasProviderEnvironment) {
            return;
        }

        if (DB::getDriverName() !== 'sqlite' && $hasWalletTransactionId) {
            $databaseName = DB::getDatabaseName();
            $constraintExists = DB::table('information_schema.table_constraints')
                ->where('table_schema', $databaseName)
                ->where('table_name', 'payments')
                ->where('constraint_name', 'payments_wallet_transaction_id_foreign')
                ->exists();

            if ($constraintExists) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropForeign('payments_wallet_transaction_id_foreign');
                });
            }
        }

        Schema::table('payments', function (Blueprint $table) use (
            $hasPurpose,
            $hasWalletTransactionId,
            $hasProviderKey,
            $hasProviderEnvironment
        ) {
            if ($hasPurpose) {
                $table->dropIndex('payments_purpose_idx');
            }

            if ($hasWalletTransactionId) {
                $table->dropIndex('payments_wallet_transaction_idx');
            }

            if ($hasProviderEnvironment) {
                $table->dropIndex('payments_provider_env_idx');
            }

            $dropColumns = [];
            if ($hasPurpose) {
                $dropColumns[] = 'purpose';
            }
            if ($hasWalletTransactionId) {
                $dropColumns[] = 'wallet_transaction_id';
            }
            if ($hasProviderKey) {
                $dropColumns[] = 'provider_key';
            }
            if ($hasProviderEnvironment) {
                $dropColumns[] = 'provider_environment';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }

    private function dropClientWalletColumns(): void
    {
        $hasWalletBalance = Schema::hasColumn('clients', 'wallet_balance');
        $hasWalletCurrency = Schema::hasColumn('clients', 'wallet_currency');
        $hasWalletLastSyncedAt = Schema::hasColumn('clients', 'wallet_last_synced_at');

        if (!$hasWalletBalance && !$hasWalletCurrency && !$hasWalletLastSyncedAt) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use (
            $hasWalletBalance,
            $hasWalletCurrency,
            $hasWalletLastSyncedAt
        ) {
            $dropColumns = [];
            if ($hasWalletBalance) {
                $dropColumns[] = 'wallet_balance';
            }
            if ($hasWalletCurrency) {
                $dropColumns[] = 'wallet_currency';
            }
            if ($hasWalletLastSyncedAt) {
                $dropColumns[] = 'wallet_last_synced_at';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
