<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Order;
use App\Services\OrderCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends MarketplaceController
{
    private const RELATIONS = ['items', 'buyer:id,name,username,imagen', 'seller:id,name,username,imagen', 'store:id,name,slug,logo'];

    public function store(Request $request, OrderCheckoutService $checkout)
    {
        $data = $request->validate([
            'uuid_cliente' => 'required|uuid',
            'items' => 'required|array|min:1|max:20',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'meeting_point' => 'nullable|string|max:160',
            'meeting_at' => 'nullable|date|after:now',
            'note' => 'nullable|string|max:500',
        ]);

        $orders = $checkout->checkout(
            $request->user(),
            $data['uuid_cliente'],
            $data['items'],
            array_intersect_key($data, array_flip(['meeting_point', 'meeting_at', 'note']))
        );

        return $this->respond(
            $orders,
            $orders->count() > 1
                ? 'Listo. Se crearon '.$orders->count().' apartados, uno por vendedor.'
                : 'Listo. Coordiná la entrega con el vendedor.',
            201
        );
    }

    /** Mis compras. */
    public function purchases(Request $request)
    {
        return $this->respond($this->listing($request, $request->user()->purchases()));
    }

    /** Mis ventas. */
    public function sales(Request $request)
    {
        return $this->respond($this->listing($request, $request->user()->sales()));
    }

    public function show(Request $request, int $order)
    {
        $record = Order::forUser($request->user()->id)->with(self::RELATIONS)->findOrFail($order);

        return $this->respond($record);
    }

    /** El punto y la hora de encuentro los puede ajustar cualquiera de las dos partes. */
    public function update(Request $request, int $order)
    {
        $data = $request->validate([
            'meeting_point' => 'sometimes|nullable|string|max:160',
            'meeting_at' => 'sometimes|nullable|date|after:now',
        ]);

        $record = Order::forUser($request->user()->id)->findOrFail($order);
        $record->update($data);

        return $this->respond($record->fresh()->load(self::RELATIONS), 'Encuentro actualizado.');
    }

    public function deliver(Request $request, int $order, OrderCheckoutService $checkout)
    {
        $record = Order::forUser($request->user()->id)->findOrFail($order);

        return $this->respond($checkout->deliver($record, $request->user()), 'Apartado entregado.');
    }

    public function cancel(Request $request, int $order, OrderCheckoutService $checkout)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $record = Order::forUser($request->user()->id)->findOrFail($order);

        return $this->respond(
            $checkout->cancel($record, $request->user(), $data['reason'] ?? null),
            'Apartado cancelado. El stock volvió a estar disponible.'
        );
    }

    private function listing(Request $request, $query)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'delivered', 'cancelled'])],
            'page' => 'sometimes|integer|min:1',
        ]);

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $query->with(self::RELATIONS)->latest()->orderBy('id', 'desc')->paginate(20);
    }
}
