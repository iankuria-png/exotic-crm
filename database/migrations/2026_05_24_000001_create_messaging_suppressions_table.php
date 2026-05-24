<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messaging_suppressions')) {
            Schema::create('messaging_suppressions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_id')->nullable();
                $table->unsignedBigInteger('platform_scope')->default(0);
                $table->string('phone_e164', 32);
                $table->string('email')->nullable();
                $table->enum('channel', ['whatsapp', 'sms', 'email', 'all']);
                $table->tinyInteger('active_marker')->nullable()->default(1);
                $table->string('reason', 64);
                $table->unsignedBigInteger('source_message_id')->nullable();
                $table->timestamp('opted_out_at');
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('platform_id')->references('id')->on('platforms')->nullOnDelete();
                $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['phone_e164', 'channel']);
                $table->index(['platform_id', 'revoked_at']);
            });
        } else {
            Schema::table('messaging_suppressions', function (Blueprint $table) {
                if (!Schema::hasColumn('messaging_suppressions', 'platform_scope')) {
                    $table->unsignedBigInteger('platform_scope')->default(0)->after('platform_id');
                }

                if (!Schema::hasColumn('messaging_suppressions', 'active_marker')) {
                    $table->tinyInteger('active_marker')->nullable()->default(1)->after('channel');
                }
            });
        }

        DB::table('messaging_suppressions')->update([
            'platform_scope' => DB::raw('COALESCE(platform_id, 0)'),
            'active_marker' => DB::raw('CASE WHEN revoked_at IS NULL THEN 1 ELSE NULL END'),
        ]);

        if (!$this->indexExists('messaging_suppressions', 'uniq_active_suppression')) {
            Schema::table('messaging_suppressions', function (Blueprint $table) {
                $table->unique(['platform_scope', 'phone_e164', 'channel', 'active_marker'], 'uniq_active_suppression');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_suppressions');
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list({$table})"))
                ->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return (bool) DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
