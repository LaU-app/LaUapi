<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Task;
use App\Models\PomodoroSession;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PomodoroSessionSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $totalSessions = 0;
        $todaySessionsCount = 0;

        foreach ($users as $user) {
            $tasks = $user->tasks;

            // ---------------------------------------------------------
            // 1. HISTORIAL PASADO (Simular trabajo previo)
            // ---------------------------------------------------------
            foreach ($tasks as $task) {
                // Decidimos al azar cuánto avanzó esta tarea en el pasado.
                // rand(0, estimados) asegura que NUNCA nos pasemos del total (evita el 5/4).
                $sessionsToGenerate = rand(0, $task->estimated_pomodoros);

                for ($i = 0; $i < $sessionsToGenerate; $i++) {
                    // Fechas aleatorias en los últimos 30 días
                    $date = Carbon::now()->subDays(rand(1, 30))->setTime(rand(8, 20), rand(0, 59));
                    
                    $this->createSession($user, $task, $date);
                    $totalSessions++;
                }
            }

            // ---------------------------------------------------------
            // 2. SESIONES SUELTAS (Sin tarea asignada - Modo Libre)
            // ---------------------------------------------------------
            $looseSessions = rand(2, 6);
            for ($i = 0; $i < $looseSessions; $i++) {
                $date = Carbon::now()->subDays(rand(1, 30))->setTime(rand(8, 20), rand(0, 59));
                $this->createSession($user, null, $date); // null task_id
                $totalSessions++;
            }

            // ---------------------------------------------------------
            // 3. SESIONES DE HOY (Para que el contador "Hoy" tenga datos)
            // ---------------------------------------------------------
            // 80% de probabilidad de que el usuario haya trabajado hoy
            if (rand(0, 100) < 80) { 
                $sessionsToday = rand(1, 6); // Entre 1 y 6 pomodoros hoy
                
                for ($i = 0; $i < $sessionsToday; $i++) {
                    // Buscamos una tarea que NO esté terminada para avanzarla hoy
                    $pendingTask = $user->tasks()
                        ->whereColumn('completed_pomodoros', '<', 'estimated_pomodoros')
                        ->where('status', '!=', 'completed')
                        ->inRandomOrder()
                        ->first();

                    // Generar hora lógica de hoy (desde las 7 AM hasta ahora)
                    $startHour = 7;
                    $endHour = Carbon::now()->hour;
                    // Si es muy temprano, forzamos al menos las 8am
                    if ($endHour < $startHour) $endHour = $startHour + 1;

                    $date = Carbon::today()->setTime(rand($startHour, $endHour), rand(0, 59));
                    
                    $this->createSession($user, $pendingTask, $date); 
                    $totalSessions++;
                    $todaySessionsCount++;
                }
            }
        }

        $this->command->info("✅ Se han generado $totalSessions sesiones ($todaySessionsCount son de HOY).");
    }

    /**
     * Crea la sesión y actualiza la lógica de la tarea
     */
    private function createSession($user, $task, $date)
    {
        $duration = 1500; // 25 min estándar

        PomodoroSession::create([
            'uuid_cliente' => (string) Str::uuid(),
            'user_id' => $user->id,
            'task_id' => $task ? $task->id : null,
            'started_at' => $date,
            'ended_at' => (clone $date)->addSeconds($duration),
            'duration_seconds' => $duration,
            'break_type' => rand(0, 1) ? 'short' : 'long',
            'status' => 'completed',
            'synced_at' => now(),
        ]);

        // Si la sesión pertenece a una tarea, actualizamos su estado y progreso
        if ($task) {
            $task->increment('completed_pomodoros');
            $task->refresh(); // Recargamos para tener los valores nuevos

            // Lógica de Estados:
            if ($task->completed_pomodoros >= $task->estimated_pomodoros) {
                // CASO 1: Meta alcanzada -> Estado 'completed'
                $task->update([
                    'status' => 'completed', 
                    'completed_at' => $date
                ]);
            } elseif ($task->completed_pomodoros > 0) {
                // CASO 2: Tiene avance (ej. 1/4) -> Estado 'in_progress'
                // Esto soluciona que aparezcan como 'pendientes' teniendo avance.
                $task->update(['status' => 'in_progress']);
            }
            // CASO 3: (Implícito) Si es 0, se queda como 'pending' (definido en TasksSeeder)
        }
    }
}