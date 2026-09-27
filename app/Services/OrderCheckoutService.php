<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apartados del Marketplace. No hay pagos: un pedido reserva stock y abre la
 * coordinación de la entrega en el campus.
 */
class OrderCheckoutService
{
    /**
     * Confirma un carrito. El carrito se parte en un pedido por vendedor.
     *
     * @param  array<int, array{product_id:int, quantity:int}>  $items
     * @return Collection<int, Order>
     */
    public function checkout(User $buyer, string $uuidCliente, array $items, array $meta = []): Collection
    {
        return DB::transaction(function () use ($buyer, $uuidCliente, $items, $meta) {
            // Idempotencia: un reintento por mala red devuelve los mismos pedidos
            // sin volver a descontar stock.
            $existingIds = Order::where('uuid_cliente', $uuidCliente)->where('buyer_id', $buyer->id)->pluck('id');
            if ($existingIds->isNotEmpty()) {
                return $this->hydrate($existingIds->all());
            }

            $quantities = [];
            foreach ($items as $item) {
                $quantities[(int) $item['product_id']] = (int) $item['quantity'];
            }

            // orderBy('id') antes del lock es obligatorio: dos carritos que tocan
            // los mismos productos en distinto orden provocarían un deadlock.
            $products = Product::whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($quantities as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product || $product->status !== 'active') {
                    throw ValidationException::withMessages(['items' => 'Alguno de los productos ya no está disponible.']);
                }
                if ($product->user_id === $buyer->id) {
                    throw ValidationException::withMessages(['items' => 'No podés apartar tus propios productos.']);
                }
                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => 'No hay suficiente stock de "'.$product->name.'". Quedan '.$product->stock.'.',
                    ]);
                }
            }

            $orders = collect();

            foreach ($products->groupBy('user_id') as $sellerId => $sellerProducts) {
                $order = Order::create([
                    'uuid_cliente' => $uuidCliente,
                    'code' => Order::generateCode(),
                    'buyer_id' => $buyer->id,
                    'seller_id' => (int) $sellerId,
                    'store_id' => $sellerProducts->first()->store_id,
                    'status' => 'pending',
                    'total' => 0,
                    'meeting_point' => $meta['meeting_point'] ?? $sellerProducts->first()->delivery_point,
                    'meeting_at' => $meta['meeting_at'] ?? null,
                    'note' => $meta['note'] ?? null,
                ]);

                $total = 0;

                foreach ($sellerProducts as $product) {
                    $quantity = $quantities[$product->id];

                    // Decremento condicional: atómico por sí mismo aunque cambie
                    // el nivel de aislamiento. Si no afecta filas, alguien se adelantó.
                    $affected = Product::whereKey($product->id)
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);

                    if ($affected === 0) {
                        throw ValidationException::withMessages([
                            'items' => 'El stock de "'.$product->name.'" cambió mientras confirmabas. Revisá tu carrito.',
                        ]);
                    }

                    // Snapshots: el apartado debe seguir siendo legible aunque
                    // el vendedor edite o borre el producto.
                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'unit_price' => $product->price,
                        'quantity' => $quantity,
                        'image_filename' => $product->images()->orderBy('position')->value('filename'),
                    ]);

                    $total += (float) $product->price * $quantity;
                }

                $order->update(['total' => round($total, 2)]);
                $orders->push($order);
            }

            return $this->hydrate($orders->pluck('id')->all());
        });
    }

    /** @return Collection<int, Order> */
    private function hydrate(array $ids): Collection
    {
        return Order::whereIn('id', $ids)
            ->with('items', 'seller:id,name,username,imagen', 'store:id,name,slug,logo')
            ->orderBy('id')
            ->get();
    }

    /** Solo el vendedor entrega. No toca stock: ya se descontó al apartar. */
    public function deliver(Order $order, User $seller): Order
    {
        return DB::transaction(function () use ($order, $seller) {
            $record = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            abort_if($record->seller_id !== $seller->id, 403, 'Solo el vendedor puede marcar la entrega.');

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Este apartado ya está '.$this->label($record->status).'.']);
            }

            $record->update(['status' => 'delivered', 'delivered_at' => now()]);

            return $record->fresh()->load('items', 'buyer:id,name,username,imagen', 'seller:id,name,username,imagen');
        });
    }

    /** Cancelan comprador o vendedor, y solo si sigue pendiente. Devuelve el stock. */
    public function cancel(Order $order, User $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            // El estado se comprueba con el pedido ya bloqueado para que dos
            // cancelaciones simultáneas no devuelvan el stock dos veces.
            $record = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            abort_if(
                $record->buyer_id !== $actor->id && $record->seller_id !== $actor->id,
                403,
                'No podés cancelar este apartado.'
            );

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Este apartado ya está '.$this->label($record->status).'.']);
            }

            foreach ($record->items as $item) {
                if ($item->product_id) {
                    Product::whereKey($item->product_id)->increment('stock', $item->quantity);
                }
            }

            $record->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ]);

            return $record->fresh()->load('items', 'buyer:id,name,username,imagen', 'seller:id,name,username,imagen');
        });
    }

    private function label(string $status): string
    {
        return ['delivered' => 'entregado', 'cancelled' => 'cancelado'][$status] ?? $status;
    }
}
