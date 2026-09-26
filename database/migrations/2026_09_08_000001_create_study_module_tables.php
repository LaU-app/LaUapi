<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->uuid('uuid_cliente');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority', 16)->default('medium');
                $table->string('status', 20)->default('pending');
                $table->timestamp('due_date')->nullable();
                $table->unsignedSmallInteger('estimated_pomodoros')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['user_id', 'uuid_cliente']);
                $table->index(['user_id', 'status', 'created_at']);
            });
        } else {
            // LaU previously shipped a tasks table from a migration that is no
            // longer present in this checkout. Extend it without deleting data.
            if (! Schema::hasColumn('tasks', 'deleted_at')) {
                Schema::table('tasks', fn (Blueprint $table) => $table->softDeletes());
            }

            $indexes = collect(Schema::getIndexes('tasks'))->pluck('name');
            if (! $indexes->contains('tasks_user_id_uuid_cliente_unique')) {
                Schema::table('tasks', fn (Blueprint $table) => $table->unique(['user_id', 'uuid_cliente']));
            }
            if (! $indexes->contains('tasks_user_id_status_created_at_index')) {
                Schema::table('tasks', fn (Blueprint $table) => $table->index(['user_id', 'status', 'created_at']));
            }
        }
        Schema::create('study_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('focus_minutes')->default(25);
            $table->unsignedSmallInteger('short_break_minutes')->default(5);
            $table->unsignedSmallInteger('long_break_minutes')->default(15);
            $table->unsignedTinyInteger('cycles_before_long_break')->default(4);
            $table->boolean('ranking_public')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('uuid_cliente');
            $table->string('activity')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('target_seconds');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'uuid_cliente']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'ended_at']);
            $table->index(['task_id', 'status']);
        });
        // Actual focus intervals, split at local midnight. Breaks/pauses have no rows.
        Schema::create('study_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->date('studied_on')->index();
            $table->unsignedInteger('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_intervals');
        Schema::dropIfExists('study_sessions');
        Schema::dropIfExists('study_preferences');

        if (! Schema::hasTable('tasks')) {
            return;
        }

        // Preserve the legacy tasks table and only remove additions made here.
        if (Schema::hasColumn('tasks', 'completed_pomodoros') || Schema::hasColumn('tasks', 'synced_at')) {
            $indexes = collect(Schema::getIndexes('tasks'))->pluck('name');
            Schema::table('tasks', function (Blueprint $table) use ($indexes) {
                if ($indexes->contains('tasks_user_id_uuid_cliente_unique')) {
                    $table->dropUnique('tasks_user_id_uuid_cliente_unique');
                }
                if ($indexes->contains('tasks_user_id_status_created_at_index')) {
                    $table->dropIndex('tasks_user_id_status_created_at_index');
                }
                if (Schema::hasColumn('tasks', 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });

            return;
        }

        Schema::drop('tasks');
    }
};
