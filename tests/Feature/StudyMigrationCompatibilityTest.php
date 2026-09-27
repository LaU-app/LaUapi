<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudyMigrationCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
        ]);
        DB::purge('sqlite');

        (require database_path('migrations/2014_10_12_000000_create_users_table.php'))->up();
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid_cliente')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('estimated_pomodoros')->default(1);
            $table->integer('completed_pomodoros')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'test']);
        DB::table('tasks')->insert(['id' => 7, 'user_id' => 1, 'title' => 'Tarea anterior']);
    }

    public function test_study_migration_extends_legacy_tasks_without_losing_rows(): void
    {
        $migration = require database_path('migrations/2026_09_08_000001_create_study_module_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('tasks', 'deleted_at'));
        $this->assertTrue(Schema::hasTable('study_preferences'));
        $this->assertTrue(Schema::hasTable('study_sessions'));
        $this->assertTrue(Schema::hasTable('study_intervals'));
        $this->assertDatabaseHas('tasks', ['id' => 7, 'title' => 'Tarea anterior']);
        $this->assertContains('tasks_user_id_uuid_cliente_unique', collect(Schema::getIndexes('tasks'))->pluck('name'));

        $migration->down();
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertFalse(Schema::hasColumn('tasks', 'deleted_at'));
        $this->assertDatabaseHas('tasks', ['id' => 7]);
    }
}
