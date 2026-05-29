<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->join('users', 'clients.created_by', '=', 'users.id')
            ->where('users.role', 'field_sales')
            ->where(function ($query) {
                $query->whereNull('clients.signup_source')
                    ->orWhere('clients.signup_source', '!=', 'field');
            })
            ->update([
                'clients.signup_source' => 'field',
                'clients.updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data repair migration; no safe way to infer each row's former source.
    }
};
