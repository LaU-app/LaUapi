<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mentioned_user_id');
            $table->unsignedBigInteger('author_id');
            $table->string('mentionable_type');
            $table->unsignedBigInteger('mentionable_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['mentionable_type', 'mentionable_id'], 'mentions_morph_index');
            $table->unique(
                ['mentionable_type', 'mentionable_id', 'mentioned_user_id'],
                'mentions_target_user_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
