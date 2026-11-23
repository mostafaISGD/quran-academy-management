<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;

class PostgresSchemaGrammar extends PostgresGrammar
{
    public function compileChange(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $column = $command->column;

        if ($column->type !== 'enum') {
            return parent::compileChange($blueprint, $command, $connection);
        }

        // PostgreSQL accepts CHECK as a separate ALTER TABLE action.
        $change = clone $command;
        $change->column = clone $column;
        $change->column->type = 'string';
        $change->column->length = 255;

        $table = $this->wrapTable($blueprint);
        $tableName = last(explode('.', $blueprint->getTable()));
        $constraint = $this->wrap($this->getTablePrefix().$tableName.'_'.$column->name.'_check');
        $name = $this->wrap($column->name);
        $allowed = $this->quoteString($column->allowed);

        return [
            "alter table {$table} drop constraint if exists {$constraint}",
            parent::compileChange($blueprint, $change, $connection),
            "alter table {$table} add constraint {$constraint} check ({$name} in ({$allowed}))",
        ];
    }
}
