<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Post;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Str;

class OptimizeExistingImages extends Command
{
    /**
     * El nombre y firma del comando en la consola.
     *
     * @var string
     */
    protected $signature = 'images:optimize';

    /**
     * La descripción del comando.
     *
     * @var string
     */
    protected $description = 'Convierte imágenes viejas de public/uploads a formato WebP (Full y Mini) para optimizar el feed';

    /**
     * Ejecuta el comando de consola.
     */
    public function handle()
    {
        // 1. Buscamos posts que tengan imagen pero que NO tengan su versión miniatura aún
        $posts = Post::whereNotNull('imagen')
                     ->whereNull('imagen_mini')
                     ->get();

        if ($posts->isEmpty()) {
            $this->info('¡Todo al día! No hay imágenes pendientes por optimizar en la base de datos.');
            return;
        }

        $this->info("Se encontraron {$posts->count()} posts para optimizar al nuevo formato doble.");
        
        // Creamos una barra de progreso visual para la terminal
        $bar = $this->output->createProgressBar(count($posts));
        $bar->start();

        // 2. Iniciamos el motor gráfico de la Versión 3 (Usando GD que viene por defecto en Laragon)
        $manager = new ImageManager(new Driver());

        foreach ($posts as $post) {
            try {
                // Obtenemos solo el nombre del archivo guardado en la BD (ej: "170000_foto.jpg")
                $oldFileName = $post->imagen; 

                // 3. Verificamos que el archivo realmente exista en la carpeta public/uploads
                if (file_exists(public_path('uploads/' . $oldFileName))) {
                    
                    $fullOldPath = public_path('uploads/' . $oldFileName);
                    
                    // Extraemos el nombre sin la extensión (.jpg o .png)
                    $nombreBase = pathinfo($oldFileName, PATHINFO_FILENAME);
                    
                    // Definimos los nuevos nombres
                    $nombreFull = $nombreBase . '_full.webp';
                    $nombreMini = $nombreBase . '_feed.webp';

                    // Leemos la imagen original vieja
                    $img = $manager->read($fullOldPath);

                    // --- CREAR VERSIÓN FULL ---
                    $imgFull = clone $img; // <-- ASÍ SE CLONA EN PHP
                    $imgFull->scaleDown(width: 1200)
                            ->toWebp(85)
                            ->save(public_path('uploads/' . $nombreFull));

                    // --- CREAR VERSIÓN MINI (FEED) ---
                    $imgMini = clone $img; // <-- ASÍ SE CLONA EN PHP
                    $imgMini->scaleDown(width: 600)
                            ->toWebp(70)
                            ->save(public_path('uploads/' . $nombreMini));

                    // --- ACTUALIZAR BASE DE DATOS ---
                    $post->imagen = $nombreFull;
                    $post->imagen_mini = $nombreMini;
                    $post->save();

                    // --- LIMPIEZA: Borrar la foto pesada original ---
                    // IMPORTANTE: Solo borramos después de haber guardado todo exitosamente
                    unlink($fullOldPath);
                } else {
                    // Si el archivo no existe físicamente, avisamos pero no detenemos el script
                    $this->warn("\nEl archivo físico no se encontró para el post ID {$post->id}: {$oldFileName}");
                }
            } catch (\Exception $e) {
                // Si una imagen falla (está corrupta, etc), la ignoramos y seguimos con la otra
                $this->error("\nError procesando el post ID {$post->id}: " . $e->getMessage());
            }

            // Avanzamos la barra de progreso
            $bar->advance();
        }

        // Finalizamos la barra
        $bar->finish();
        
        $this->info("\n\n¡Optimización completada con éxito! LaÚ ahora es mucho más rápida.");
    }
}