<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('study_intervals'))->pluck('name');
        if (! $indexes->contains('study_intervals_studied_on_study_session_id_index')) {
            Schema::table('study_intervals', fn (Blueprint $table) => $table->index(
                ['studied_on', 'study_session_id'],
                'study_intervals_studied_on_study_session_id_index'
            ));
        }
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('study_intervals'))->pluck('name');
        if ($indexes->contains('study_intervals_studied_on_study_session_id_index')) {
            Schema::table('study_intervals', fn (Blueprint $table) => $table->dropIndex(
                'study_intervals_studied_on_study_session_id_index'
            ));
        }
    }
};
