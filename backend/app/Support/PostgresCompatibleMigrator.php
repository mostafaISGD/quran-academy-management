<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;

class PostgresCompatibleMigrator extends Migrator
{
    protected function resolvePath(string $path)
    {
        $alternative = database_path('migrations-postgres/'.basename($path));

        if (is_file($alternative) && $this->resolveConnection(null)->getDriverName() === 'pgsql') {
            return parent::resolvePath($alternative);
        }

        return parent::resolvePath($path);
    }
}
