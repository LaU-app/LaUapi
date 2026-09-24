<?php

namespace Database\Seeders;

use App\Models\Universidad;
use Illuminate\Database\Seeder;

class UniversidadSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Verificando y creando universidades...');

        $universidades = [
            ['nombre' => 'Universidad de El Salvador', 'dominio' => 'ues.edu.sv'],
            ['nombre' => 'Universidad Centroamericana José Simeón Cañas', 'dominio' => 'uca.edu.sv'],
            ['nombre' => 'Universidad Tecnológica de El Salvador', 'dominio' => 'utec.edu.sv'],
            ['nombre' => 'Universidad Don Bosco', 'dominio' => 'udb.edu.sv'],
            ['nombre' => 'Universidad Gerardo Barrios', 'dominio' => 'ugb.edu.sv'],
            ['nombre' => 'Universidad Francisco Gavidia', 'dominio' => 'ufg.edu.sv'],
            ['nombre' => 'ITCA-FEPADE', 'dominio' => 'itca.edu.sv'],
            ['nombre' => 'Universidad Doctor José Matías Delgado', 'dominio' => 'ujmd.edu.sv'],
            ['nombre' => 'Universidad Dr. Andrés Bello', 'dominio' => 'unab.edu.sv'],
            ['nombre' => 'Universidad Salvadoreña Alberto Masferrer', 'dominio' => 'usam.edu.sv'],
        ];

        foreach ($universidades as $data) {
            // Si ya existe, no hace nada y sigue con la siguiente
            Universidad::firstOrCreate(
                ['nombre' => $data['nombre']], 
                ['dominio' => $data['dominio'] ?? null]
            );
        }

        $this->command->info("Universidades sincronizadas correctamente.");
    }
}