<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ProductImageController extends MarketplaceController
{
    /** Carpeta física bajo public/, como uploads/ perfiles/ files/. */
    public const DIRECTORY = 'marketplace';

    public const MAX_PER_PRODUCT = 6;

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|image|mimes:webp,jpeg,jpg,png|max:5120',
        ]);

        $file = $request->file('file');

        // El frontend manda TODAS las imágenes con el nombre literal "image.webp",
        // así que el nombre lo genera el servidor o se pisarían entre sí.
        $extension = strtolower($file->getClientOriginalExtension() ?: 'webp');
        $filename = Str::uuid().'.'.$extension;

        $directory = public_path(self::DIRECTORY);
        File::ensureDirectoryExists($directory, 0755);

        try {
            // El cliente ya redimensiona, pero este endpoint es superficie pública:
            // cualquiera puede mandar un JPEG de 12 MP.
            $manager = new ImageManager(new Driver());
            $image = $manager->read($file);
            if ($image->width() > 1080 || $image->height() > 1080) {
                $image->scaleDown(1080, 1080);
            }
            $image->save($directory.DIRECTORY_SEPARATOR.$filename, 80);
        } catch (\Throwable $e) {
            $file->move($directory, $filename);
        }

        return $this->respond([
            'filename' => $filename,
            'url' => url(self::DIRECTORY.'/'.$filename),
        ], 'Imagen subida correctamente.', 201);
    }

    public function destroy(Request $request, int $image)
    {
        $record = ProductImage::whereKey($image)
            ->whereHas('product', fn ($q) => $q->where('user_id', $request->user()->id))
            ->firstOrFail();

        $filename = $record->filename;
        $record->delete();

        // Solo se borra el fichero si ningún pedido conserva ese snapshot.
        $stillReferenced = ProductImage::where('filename', $filename)->exists()
            || \App\Models\OrderItem::where('image_filename', $filename)->exists();

        if (! $stillReferenced) {
            File::delete(public_path(self::DIRECTORY.'/'.$filename));
        }

        return $this->respond(null, 'Imagen eliminada.');
    }
}
