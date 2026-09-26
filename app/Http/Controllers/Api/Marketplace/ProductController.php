<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends MarketplaceController
{
    /**
     * El filename viene del cliente y se concatena a una ruta de disco:
     * sin esta whitelist hay path traversal (../../.env).
     */
    private const FILENAME_PATTERN = '/^[A-Za-z0-9\-]+\.(webp|jpe?g|png)$/';

    private const RELATIONS = ['images', 'category:id,name,slug,icon', 'user:id,name,username,imagen', 'store:id,name,slug,logo'];

    private function validateProduct(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'name' => $required.'|string|max:120',
            'description' => 'nullable|string|max:5000',
            'price' => $required.'|numeric|min:0|max:99999999.99',
            'stock' => $required.'|integer|min:0|max:100000',
            'category_id' => $required.'|integer|exists:product_categories,id',
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'condition' => ['nullable', Rule::in(['nuevo', 'como_nuevo', 'buen_estado', 'aceptable'])],
            'delivery_point' => 'nullable|string|max:120',
            'images' => 'sometimes|array|max:'.ProductImageController::MAX_PER_PRODUCT,
            'images.*' => ['required', 'string', 'max:255', 'regex:'.self::FILENAME_PATTERN],
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:80',
            'category_id' => 'sometimes|integer',
            'store_id' => 'sometimes|integer',
            'seller_id' => 'sometimes|integer',
            'universidad_id' => 'sometimes|integer',
            'min_price' => 'sometimes|numeric|min:0',
            'max_price' => 'sometimes|numeric|min:0',
            'only_available' => 'sometimes|in:true,false,1,0',
            'sort_by' => ['sometimes', Rule::in(['created_at', 'price'])],
            'sort_order' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = Product::with(self::RELATIONS)->visible();

        if ($request->boolean('only_available', true)) {
            $query->where('stock', '>', 0);
        }
        if (isset($data['search'])) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }
        if (isset($data['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $data['category']));
        }
        foreach (['category_id' => 'category_id', 'store_id' => 'store_id', 'seller_id' => 'user_id', 'universidad_id' => 'universidad_id'] as $param => $column) {
            if (isset($data[$param])) {
                $query->where($column, $data[$param]);
            }
        }
        if (isset($data['min_price'])) {
            $query->where('price', '>=', $data['min_price']);
        }
        if (isset($data['max_price'])) {
            $query->where('price', '<=', $data['max_price']);
        }

        return $this->respond(
            $query->orderBy($data['sort_by'] ?? 'created_at', $data['sort_order'] ?? 'desc')->orderBy('id')->paginate(20)
        );
    }

    public function show(Request $request, int $product)
    {
        return $this->respond(Product::with(self::RELATIONS)->visible()->findOrFail($product));
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request, true);
        $images = $this->resolveImages($data);

        $product = DB::transaction(function () use ($request, $data, $images) {
            $record = $request->user()->products()->create([
                ...$data,
                'universidad_id' => $request->user()->universidad_id,
                'store_id' => $request->user()->store()->value('id'),
            ]);
            // Al crear, "sin images" significa simplemente que no trae fotos.
            $this->syncImages($record, $images ?? []);

            return $record;
        });

        return $this->respond($product->fresh()->load(self::RELATIONS), 'Producto publicado.', 201);
    }

    public function update(Request $request, int $product)
    {
        $data = $this->validateProduct($request, false);
        $images = $this->resolveImages($data);

        $record = DB::transaction(function () use ($request, $product, $data, $images) {
            // Scoping por relación: si no es tuyo, 404. Nunca se compara un id del cliente.
            $record = $request->user()->products()->findOrFail($product);
            $record->update($data);
            if ($images !== null) {
                $this->syncImages($record, $images);
            }

            return $record;
        });

        return $this->respond($record->fresh()->load(self::RELATIONS), 'Producto actualizado.');
    }

    public function destroy(Request $request, int $product)
    {
        // Soft delete: los pedidos históricos deben seguir resolviendo el producto.
        $request->user()->products()->findOrFail($product)->delete();

        return $this->respond(null, 'Producto eliminado.');
    }

    /** Mis publicaciones, incluidas las pausadas y sin stock. */
    public function mine(Request $request)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = $request->user()->products()->with(self::RELATIONS);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $this->respond($query->latest()->orderBy('id')->paginate(20));
    }

    /**
     * Devuelve los filenames validados, o null si la petición no trae la clave
     * (en un update, "sin images" significa "no tocar las imágenes").
     */
    private function resolveImages(array &$data): ?array
    {
        if (! array_key_exists('images', $data)) {
            return null;
        }

        $images = $data['images'];
        unset($data['images']);

        foreach ($images as $filename) {
            // Segunda barrera tras la regex: el fichero tiene que existir de verdad.
            if (! is_file(public_path(ProductImageController::DIRECTORY.'/'.$filename))) {
                throw ValidationException::withMessages([
                    'images' => 'Alguna de las imágenes no se subió correctamente. Vuelve a intentarlo.',
                ]);
            }
        }

        return array_values($images);
    }

    /** El orden del array ES el orden; la portada es position 0. */
    private function syncImages(Product $product, array $filenames): void
    {
        $product->images()->delete();
        foreach ($filenames as $position => $filename) {
            $product->images()->create(['filename' => $filename, 'position' => $position]);
        }
    }
}
