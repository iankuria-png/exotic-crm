<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsCompletedAt = !Schema::hasColumn('payments', 'completed_at');
        $needsFailureReason = !Schema::hasColumn('payments', 'failure_reason');
        $needsReferenceNumber = !Schema::hasColumn('payments', 'reference_number');
        $needsPaymentData = !Schema::hasColumn('payments', 'payment_data');

        if ($needsCompletedAt || $needsFailureReason || $needsReferenceNumber || $needsPaymentData) {
            Schema::table('payments', function (Blueprint $table) use (
                $needsCompletedAt,
                $needsFailureReason,
                $needsReferenceNumber,
                $needsPaymentData
            ) {
                if ($needsCompletedAt) {
                    $table->dateTime('completed_at')->nullable()->after('confirmed_at');
                }

                if ($needsFailureReason) {
                    $table->string('failure_reason')->nullable()->after('status');
                }

                if ($needsReferenceNumber) {
                    $table->string('reference_number')->nullable()->after('transaction_reference');
                }

                if ($needsPaymentData) {
                    $table->json('payment_data')->nullable()->after('raw_payload');
                }
            });
        }

        DB::table('payments')
            ->where('status', 'success')
            ->update([
                'status' => 'completed',
                'completed_at' => DB::raw('COALESCE(completed_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        $hasCompletedAt = Schema::hasColumn('payments', 'completed_at');
        $hasFailureReason = Schema::hasColumn('payments', 'failure_reason');
        $hasReferenceNumber = Schema::hasColumn('payments', 'reference_number');
        $hasPaymentData = Schema::hasColumn('payments', 'payment_data');

        if (!$hasCompletedAt && !$hasFailureReason && !$hasReferenceNumber && !$hasPaymentData) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) use (
            $hasCompletedAt,
            $hasFailureReason,
            $hasReferenceNumber,
            $hasPaymentData
        ) {
            $dropColumns = [];

            if ($hasCompletedAt) {
                $dropColumns[] = 'completed_at';
            }

            if ($hasFailureReason) {
                $dropColumns[] = 'failure_reason';
            }

            if ($hasReferenceNumber) {
                $dropColumns[] = 'reference_number';
            }

            if ($hasPaymentData) {
                $dropColumns[] = 'payment_data';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
