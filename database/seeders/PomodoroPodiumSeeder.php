<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\PomodoroSession;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PomodoroPodiumSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        
        if ($users->isEmpty()) return;

        // Seleccionar 3 usuarios para ser los "Top Performers" de la semana
        $topUsers = $users->random(min(3, $users->count()));

        foreach ($topUsers as $index => $user) {
            // El top 1 tendrá mas sesiones, el 2 menos, etc.
            $extraSessions = match($index) {
                0 => rand(15, 20), // Oro
                1 => rand(10, 14), // Plata
                2 => rand(5, 9),   // Bronce
                default => 0
            };

            $this->command->info("🏆 Generando podio para usuario: {$user->name} ($extraSessions sesiones extra)");

            for ($i = 0; $i < $extraSessions; $i++) {
                // Generar sesiones DENTRO de la semana actual para que salgan en el podio semanal
                $date = Carbon::now()->startOfWeek()->addDays(rand(0, Carbon::now()->dayOfWeek))->setTime(rand(8, 22), rand(0, 59));
                
                // Creamos sesiones "sueltas" (task_id = null) para no afectar tareas específicas
                // Opcional: Podrías crear tareas "falsas" al vuelo si el podio exige task_id
                PomodoroSession::create([
                    'uuid_cliente' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'task_id' => null, // Sin tarea vinculada, solo cuenta para el tiempo total/podio
                    'started_at' => $date,
                    'ended_at' => (clone $date)->addSeconds(1500),
                    'duration_seconds' => 1500,
                    'break_type' => 'short',
                    'status' => 'completed',
                    'synced_at' => now(),
                ]);
            }
        }
        
        $this->command->info('Datos del podio generados (Top users inflados).');
    }
}