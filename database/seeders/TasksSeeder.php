<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Task;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class TasksSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('es_ES');
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->warn('No hay usuarios para asignar tareas. Ejecuta UserSeeder primero.');
            return;
        }

        $asignaturas = ['Cálculo', 'Programación', 'Física', 'Estadística', 'Base de Datos', 'Historia', 'Inglés', 'Redes', 'Ética', 'Algoritmos'];
        $acciones = ['Estudiar examen de', 'Hacer tarea de', 'Preparar exposición de', 'Leer capítulo de', 'Resolver guía de', 'Proyecto final de'];

        foreach ($users as $user) {
            // Crear entre 8 y 12 tareas por usuario
            for ($i = 0; $i < rand(8, 12); $i++) {
                $asignatura = $asignaturas[array_rand($asignaturas)];
                $accion = $acciones[array_rand($acciones)];
                $titulo = "$accion $asignatura";

                // Estimado lógico: entre 2 y 6 pomodoros
                $estimated = rand(2, 6);

                Task::create([
                    'uuid_cliente' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'title' => $titulo,
                    'description' => $faker->sentence(10),
                    'status' => 'pending',
                    'priority' => $faker->randomElement(['low', 'medium', 'high']),
                    'due_date' => $faker->dateTimeBetween('-1 week', '+1 month'),
                    // CORRECCIÓN: Usar nombres exactos de la BD
                    'estimated_pomodoros' => $estimated, 
                    'completed_pomodoros' => 0,
                    'synced_at' => now(),
                ]);
            }
        }
        
        $this->command->info('✅ Tareas universitarias creadas correctamente.');
    }
}