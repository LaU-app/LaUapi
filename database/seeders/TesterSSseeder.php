<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TesterSSseeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Iniciando actualización de TesterSivarSocial...');

        $this->call([
            UniversidadSeeder::class,
            CarreraSeeder::class, 
            CarreraUniversidadSeeder::class,

            // 2. PRODUCTIVIDAD (Generar datos para usuarios existentes)
            // TasksSeeder buscará User::all() (los usuarios reales) y les creará tareas
            TasksSeeder::class,
            
            // PomodoroSessionSeeder tomará esas tareas nuevas y generará el historial
            PomodoroSessionSeeder::class,
            
            // Genera el ranking basado en lo anterior
            PomodoroPodiumSeeder::class,
        ]);

        $this->command->info('Actualización de TesterSivarSocial completada con éxito.');
    }
}

// COMANDO PARA EJECUTAR ESTE SEEDER: php artisan db:seed --class=TesterSSseeder