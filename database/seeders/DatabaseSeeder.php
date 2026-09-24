<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. Infraestructura (Para que los usuarios puedan tener carrera/uni)
            UniversidadSeeder::class,
            CarreraSeeder::class,
            CarreraUniversidadSeeder::class,

            // 2. Usuarios (NECESARIOS para que existan dueños de tareas)
            SuperUserSeeder::class, // Tu admin
            UserSeeder::class,      // Los usuarios normales

            // 3. Pomodoro y Tareas (Tu lógica nueva)
            TasksSeeder::class,           
            PomodoroSessionSeeder::class, 
            PomodoroPodiumSeeder::class,
        ]);
    }
}