<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Marketplace\ProductImageController;
use App\Models\OrderItem;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneMarketplaceImages extends Command
{
    protected $signature = 'marketplace:prune-images {--hours=24 : Antigüedad mínima del archivo} {--dry-run : Solo listar}';

    protected $description = 'Borra imágenes de productos que se subieron pero nunca se asociaron a una publicación';

    public function handle(): int
    {
        $directory = public_path(ProductImageController::DIRECTORY);

        if (! File::isDirectory($directory)) {
            $this->info('No hay carpeta de imágenes todavía. Nada que limpiar.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours((int) $this->option('hours'))->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');

        // Un filename sigue en uso si lo referencia un producto o el snapshot de un pedido.
        $inUse = ProductImage::pluck('filename')
            ->merge(OrderItem::whereNotNull('image_filename')->pluck('image_filename'))
            ->flip();

        $removed = 0;
        $freed = 0;

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() > $cutoff || $inUse->has($file->getFilename())) {
                continue;
            }

            $freed += $file->getSize();
            $removed++;

            if ($dryRun) {
                $this->line('  huérfana: '.$file->getFilename());
            } else {
                File::delete($file->getPathname());
            }
        }

        $this->info(sprintf(
            '%s %d imagen(es) huérfana(s), %s KB.',
            $dryRun ? 'Se borrarían' : 'Borradas',
            $removed,
            number_format($freed / 1024, 1)
        ));

        return self::SUCCESS;
    }
}
