<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class PostgresSchemaGrammarTest extends TestCase
{
    public function test_enum_changes_compile_as_separate_postgres_table_actions(): void
    {
        config(['database.connections.schema_probe' => [
            'driver' => 'pgsql',
            'database' => 'schema_probe',
            'prefix' => '',
        ]]);
        $connection = DB::connection('schema_probe');
        // Pretend mode compiles PostgreSQL SQL without opening a database connection.
        $connection->setPdo(new PDO('sqlite::memory:'));
        $schema = $connection->getSchemaBuilder();

        $queries = array_column($connection->pretend(function () use ($schema): void {
            $schema->table('notifications', function (Blueprint $table): void {
                $table->enum('event_type', ['invoice_issued', 'salary_paid'])->change();
            });
        }), 'query');

        $this->assertSame('alter table "notifications" drop constraint if exists "notifications_event_type_check"', $queries[0]);
        $this->assertStringContainsString('alter column "event_type" type varchar(255)', $queries[1]);
        $this->assertStringNotContainsString('check', $queries[1]);
        $this->assertSame(
            'alter table "notifications" add constraint "notifications_event_type_check" check ("event_type" in (\'invoice_issued\', \'salary_paid\'))',
            $queries[2],
        );
    }
}
