<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class DbHelper
{
    public static function setDynamicWpConnection($platform)
    {
        $connectionName = 'wp_dynamic';

        Config::set("database.connections.$connectionName", [
            'driver' => 'mysql',
            'host' => $platform->db_host,
            'database' => $platform->db_name,
            'username' => $platform->db_user,
            'password' => $platform->db_pass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $platform->db_prefix,
            'strict' => false,
            'engine' => null,
        ]);
    }
}
