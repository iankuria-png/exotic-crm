<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DynamicDatabaseService
{
    public static function switchConnection($connectionName, $config)
    {
        Config::set("database.connections.{$connectionName}", $config);
        DB::purge($connectionName);
        DB::reconnect($connectionName);
    }
}