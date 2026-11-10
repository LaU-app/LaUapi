<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chats del agente de compras de LaU Marketplace (historial por estudiante). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            // Búsqueda inteligente desde la que se abrió el chat (si la hay).
            $table->foreignId('assistant_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // user | assistant
            $table->text('content');
            // Tarjetas que ve el estudiante (productos, propuesta de compra, videos), tomadas de datos reales.
            $table->json('cards')->nullable();
            // Herramientas que usó el agente en este turno (lo que se muestra como "pasos").
            $table->json('steps')->nullable();
            // Auditoría: modelo, juicios de Jev, reintentos, tiempos.
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
        Schema::dropIfExists('agent_conversations');
    }
};
