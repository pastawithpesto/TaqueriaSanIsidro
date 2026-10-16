<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Un cliente mandó un pedido: avisa al taquero para que suene y aparezca en Por aceptar. */
class OnlineOrderPlaced implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Order $order) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin-orders')];
    }

    public function broadcastAs(): string
    {
        return 'placed';
    }

    /** @return array{id: int, name: ?string, total: float, service: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'name' => $this->order->label,
            'total' => (float) $this->order->total,
            'service' => $this->order->service,
        ];
    }
}
