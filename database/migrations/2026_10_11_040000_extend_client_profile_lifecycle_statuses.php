<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->change(false);
    }

    public function down(): void
    {
        if (DB::table('clients')->whereIn('profile_status', ['trash', 'deleted'])->exists()) {
            throw new RuntimeException('Cannot remove lifecycle statuses while trash/deleted clients exist. Preserve records and use a forward repair.');
        }
        $this->change(true);
    }

    private function change(bool $down): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->changeSqlite($down);

            return;
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Unsupported lifecycle migration database.');
        }
        $connection = DB::connection();
        $column = DB::selectOne('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$connection->getTablePrefix().'clients', 'profile_status']);
        if (! $column || ! preg_match("/^enum\((.*)\)$/", $column->COLUMN_TYPE, $m)) {
            throw new RuntimeException('Expected existing profile status enum.');
        }
        $values = str_getcsv($m[1], ',', "'", '\\');
        if (array_filter($values, fn ($v) => ! preg_match('/^[a-z_]+$/D', $v))) {
            throw new RuntimeException('Unexpected enum definition.');
        }
        $values = $down ? array_values(array_diff($values, ['trash', 'deleted'])) : array_values(array_unique(array_merge($values, ['trash', 'deleted'])));
        $quote = fn ($value) => $connection->getPdo()->quote($value);
        $default = $column->COLUMN_DEFAULT;
        if ($default !== null && strtolower((string) $default) !== 'null') {
            $default = trim((string) $default, "'");
            if (! in_array($default, $values, true)) {
                throw new RuntimeException('Existing enum default cannot be preserved.');
            }
            $defaultSql = ' DEFAULT '.$quote($default);
        } else {
            $defaultSql = $column->IS_NULLABLE === 'YES' ? ' DEFAULT NULL' : '';
        }
        $collation = (string) $column->COLLATION_NAME;
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $collation)) {
            throw new RuntimeException('Unexpected column collation.');
        }
        $table = $connection->getQueryGrammar()->wrapTable('clients');
        DB::statement('ALTER TABLE '.$table.' MODIFY COLUMN `profile_status` ENUM('.implode(',', array_map($quote, $values)).') COLLATE '.$collation
            .($column->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL').$defaultSql.' COMMENT '.$quote($column->COLUMN_COMMENT));
    }

    private function changeSqlite(bool $down): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('SQLite lifecycle migration requires an independent migration transaction.');
        }
        $name = $connection->getTablePrefix().'clients';
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$name]);
        $values = $down ? ['publish', 'private', 'draft', 'pending'] : ['publish', 'private', 'draft', 'pending', 'trash', 'deleted'];
        $new = preg_replace('/check \(\s*"profile_status"\s+in\s*\([^)]*\)\s*\)/i',
            'check ("profile_status" in ('.implode(', ', array_map(fn ($v) => $connection->getPdo()->quote($v), $values)).'))', $row->sql ?? '', 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Expected SQLite profile status constraint.');
        }
        $temporary = $name.'_lifecycle_rebuild';
        if (DB::selectOne('SELECT name FROM sqlite_master WHERE name=?', [$temporary])) {
            throw new RuntimeException('Lifecycle rebuild table already exists.');
        }
        $new = preg_replace('/^CREATE TABLE\s+(?:"[^"\n]+"|`[^`]+`|\w+)/i', 'CREATE TABLE "'.$temporary.'"', $new, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected SQLite table definition.');
        }
        $objects = DB::select("SELECT sql FROM sqlite_master WHERE tbl_name=? AND type IN ('index','trigger') AND sql IS NOT NULL", [$name]);
        $views = DB::select("SELECT name, sql FROM sqlite_master WHERE type='view'");
        $foreignKeys = (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
        DB::statement('PRAGMA foreign_keys=OFF');
        try {
            DB::transaction(function () use ($new, $name, $temporary, $objects, $views) {
                DB::statement($new);
                DB::statement('INSERT INTO "'.$temporary.'" SELECT * FROM "'.$name.'"');
                foreach ($views as $view) {
                    DB::statement('DROP VIEW '.DB::connection()->getQueryGrammar()->wrapTable($view->name));
                }
                DB::statement('DROP TABLE "'.$name.'"');
                DB::statement('ALTER TABLE "'.$temporary.'" RENAME TO "'.$name.'"');
                foreach ($views as $view) {
                    DB::statement($view->sql);
                }
                foreach ($objects as $object) {
                    DB::statement($object->sql);
                }
                if (DB::select('PRAGMA foreign_key_check')) {
                    throw new RuntimeException('Lifecycle migration failed foreign-key verification.');
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys='.($foreignKeys ? 'ON' : 'OFF'));
        }
    }
};
