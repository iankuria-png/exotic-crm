<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    // Idempotent: skips if the system user already exists.
    public function up(): void
    {
        $email = 'automation+auto-optimize@system.local';

        $exists = DB::table('users')->where('email', $email)->exists();

        if (!$exists) {
            DB::table('users')->insert([
                'name' => 'Auto Optimize Engine',
                'email' => $email,
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'role' => 'admin',
                'status' => 'inactive', // cannot log in
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'automation+auto-optimize@system.local')->delete();
    }
};
