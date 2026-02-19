<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
        'name' => 'System Admin',
        'email' => 'swizzsoft@exotic-online.com',
        'password' => Hash::make('admin1234'),
        'role' => 'admin',
    ]);
    }
}

