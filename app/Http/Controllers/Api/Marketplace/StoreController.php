<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreController extends MarketplaceController
{
    private const LOGO_DIRECTORY = 'tiendas';

    /** Umbral de "poco stock" en el panel. */
    private const LOW_STOCK = 5;

    /** Vitrina pública de una tienda. */
    public function show(Store $store)
    {
        abort_unless($store->is_active, 404);

        return $this->respond([
            'store' => $store->load('category:id,name,slug,icon', 'user:id,name,username,imagen'),
            'products' => $store->products()->with('images', 'category:id,name,slug,icon')->visible()->latest()->paginate(20),
        ]);
    }

    public function mine(Request $request)
    {
        return $this->respond($request->user()->store()->with('category:id,name,slug,icon')->first());
    }

    public function store(Request $request)
    {
        // Consulta directa en vez de la relación cacheada del modelo.
        if ($request->user()->store()->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya tenés una tienda. Podés editarla.']);
        }

        $data = $this->validateStore($request, true);
        $logo = $this->storeLogo($request);

        $store = $request->user()->store()->create([
            ...$data,
            'slug' => Store::uniqueSlug($data['name']),
            'logo' => $logo,
        ]);

        // Las publicaciones que ya tenía el estudiante pasan a colgar de su tienda.
        $request->user()->products()->whereNull('store_id')->update(['store_id' => $store->id]);

        return $this->respond($store->load('category:id,name,slug,icon'), '¡Tu tienda está lista!', 201);
    }

    public function update(Request $request)
    {
        $store = $request->user()->store()->first();
        abort_unless($store, 404, 'Todavía no tenés una tienda.');

        $data = $this->validateStore($request, false);

        if (isset($data['name']) && $data['name'] !== $store->name) {
            $data['slug'] = Store::uniqueSlug($data['name'], $store->id);
        }
        if ($logo = $this->storeLogo($request)) {
            if ($store->logo) {
                File::delete(public_path(self::LOGO_DIRECTORY.'/'.$store->logo));
            }
            $data['logo'] = $logo;
        }

        $store->update($data);

        return $this->respond($store->fresh()->load('category:id,name,slug,icon'), 'Tienda actualizada.');
    }

    /** Métricas reales del panel: nada hardcodeado. */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $store = $user->store()->with('category:id,name,slug,icon')->first();

        $startOfMonth = now()->startOfMonth();
        $startOfPreviousMonth = now()->subMonthNoOverflow()->startOfMonth();

        $deliveredThisMonth = (float) $user->sales()->where('status', 'delivered')
            ->where('delivered_at', '>=', $startOfMonth)->sum('total');

        $deliveredPreviousMonth = (float) $user->sales()->where('status', 'delivered')
            ->whereBetween('delivered_at', [$startOfPreviousMonth, $startOfMonth])->sum('total');

        return $this->respond([
            'store' => $store,
            'metrics' => [
                'delivered_this_month' => round($deliveredThisMonth, 2),
                'delivered_previous_month' => round($deliveredPreviousMonth, 2),
                'change_percent' => $deliveredPreviousMonth > 0
                    ? round((($deliveredThisMonth - $deliveredPreviousMonth) / $deliveredPreviousMonth) * 100, 1)
                    : null,
                'pending_orders' => $user->sales()->where('status', 'pending')->count(),
                'published_products' => $user->products()->where('status', 'active')->count(),
                'delivered_total' => $user->sales()->where('status', 'delivered')->count(),
            ],
            'recent_orders' => $user->sales()->with('items', 'buyer:id,name,username,imagen')
                ->latest()->limit(5)->get(),
            'low_stock' => $user->products()->with('images')->visible()
                ->where('stock', '<=', self::LOW_STOCK)
                ->orderBy('stock')->limit(5)->get(),
        ]);
    }

    private function validateStore(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'name' => $required.'|string|max:80',
            'category_id' => $required.'|integer|exists:product_categories,id',
            'description' => 'nullable|string|max:2000',
            'instagram' => 'nullable|string|max:60',
            'whatsapp' => 'nullable|string|max:30',
            'is_active' => 'sometimes|boolean',
            'logo' => 'sometimes|file|image|mimes:webp,jpeg,jpg,png|max:5120',
        ]);
    }

    private function storeLogo(Request $request): ?string
    {
        if (! $request->hasFile('logo')) {
            return null;
        }

        $file = $request->file('logo');
        $filename = Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'webp');
        $directory = public_path(self::LOGO_DIRECTORY);
        File::ensureDirectoryExists($directory, 0755);
        $file->move($directory, $filename);

        return $filename;
    }
}
