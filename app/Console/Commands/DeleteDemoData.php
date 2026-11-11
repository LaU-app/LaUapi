<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Borra todos los datos ficticios del Marketplace (catálogo de demostración y vitrina):
 * cuentas, tiendas, productos, fotos, pedidos y mensajes de esas cuentas.
 * No toca a ningún usuario real: solo correos de los dominios de demostración y las cuentas de vitrina.
 *
 *   php artisan lau:borrar-demo            (pide confirmación)
 *   php artisan lau:borrar-demo --force    (sin preguntar)
 */
class DeleteDemoData extends Command
{
    protected $signature = 'lau:borrar-demo {--force : no pedir confirmación}';

    protected $description = 'Borra las cuentas, tiendas y productos ficticios del Marketplace';

    /** Dominios que usa el catálogo de demostración (MarketplaceCatalogSeeder). */
    private const DOMAINS = ['negocios.lauapp.es', 'estudiantes.lauapp.es'];

    /** Cuentas de vitrina (MarketplaceShowcaseSeeder y marketplace:demo-accounts). */
    private const EMAILS = ['comercio.demo@lauapp.es', 'estudiante.demo@lauapp.es', 'hola@tecnocampus.lau.test'];

    public function handle(): int
    {
        $users = User::query()
            ->where(function ($q) {
                foreach (self::DOMAINS as $domain) {
                    $q->orWhere('email', 'like', '%@'.$domain);
                }
                $q->orWhereIn('email', self::EMAILS);
            })
            ->get(['id', 'email']);

        if ($users->isEmpty()) {
            $this->info('No hay datos ficticios.');

            return self::SUCCESS;
        }

        $ids = $users->pluck('id')->all();
        $stores = Store::whereIn('user_id', $ids)->get(['id', 'logo']);
        $storeIds = $stores->pluck('id')->all();
        $products = Product::withTrashed()->whereIn('user_id', $ids)->orWhereIn('store_id', $storeIds)->with('images')->get();

        $this->line(sprintf('Se borrarán %d cuentas, %d tiendas y %d productos ficticios (con sus pedidos y mensajes).', count($ids), count($storeIds), $products->count()));
        if (! $this->option('force') && ! $this->confirm('¿Continuar?')) {
            return self::FAILURE;
        }

        $files = $products->flatMap(fn ($p) => $p->images->pluck('filename'))->map(fn ($f) => public_path('marketplace/'.$f))
            ->merge($stores->pluck('logo')->filter()->map(fn ($f) => public_path('tiendas/'.$f)))
            ->all();

        DB::transaction(function () use ($ids, $storeIds, $products) {
            $productIds = $products->pluck('id')->all();
            $orderIds = DB::table('orders')
                ->whereIn('buyer_id', $ids)->orWhereIn('seller_id', $ids)->orWhereIn('store_id', $storeIds)
                ->pluck('id')->all();

            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_items')->whereIn('product_id', $productIds)->update(['product_id' => null]);
            if (Schema::hasTable('assistant_decisions')) {
                DB::table('assistant_decisions')->whereIn('order_id', $orderIds)->update(['order_id' => null]);
                DB::table('assistant_decisions')->whereIn('recommended_product_id', $productIds)->update(['recommended_product_id' => null]);
                DB::table('assistant_decisions')->whereIn('selected_product_id', $productIds)->update(['selected_product_id' => null]);
            }
            DB::table('orders')->whereIn('id', $orderIds)->delete();
            DB::table('product_images')->whereIn('product_id', $productIds)->delete();
            DB::table('products')->whereIn('id', $productIds)->delete();

            if (Schema::hasTable('ch_messages')) {
                DB::table('ch_messages')->whereIn('from_id', $ids)->orWhereIn('to_id', $ids)->delete();
            }
            if (Schema::hasTable('ch_favorites')) {
                DB::table('ch_favorites')->whereIn('user_id', $ids)->orWhereIn('favorite_id', $ids)->delete();
            }
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();

            // Tiendas: verificación y pasarelas se borran en cascada.
            DB::table('store_payment_gateways')->whereIn('store_id', $storeIds)->delete();
            DB::table('store_verifications')->whereIn('store_id', $storeIds)->delete();
            DB::table('stores')->whereIn('id', $storeIds)->delete();

            // Cualquier otra tabla con user_id (seguidores, notificaciones, likes…).
            foreach (['followers' => ['follower_id', 'following_id'], 'notifications' => ['user_id']] as $table => $columns) {
                if (Schema::hasTable($table)) {
                    foreach ($columns as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            DB::table($table)->whereIn($column, $ids)->delete();
                        }
                    }
                }
            }

            DB::table('users')->whereIn('id', $ids)->delete();
        });

        File::delete($files);
        $this->info('Listo: datos ficticios borrados.');

        return self::SUCCESS;
    }
}
