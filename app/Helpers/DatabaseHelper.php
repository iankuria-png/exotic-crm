<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseHelper
{
    public static function configureWordpressConnection($platform)
    {
        $connectionName = 'wordpress' . $platform->id;

        Config::set("database.connections.$connectionName", [
            'driver' => 'mysql',
            'host' => $platform->db_host,
            'port' => 3306,
            'database' => $platform->db_name,
            'username' => $platform->db_user,
            'password' => $platform->db_pass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $platform->db_prefix,
        ]);

        // Optional: Reconnect
        DB::purge($connectionName);
        DB::reconnect($connectionName);

        return $connectionName;
    }
}

