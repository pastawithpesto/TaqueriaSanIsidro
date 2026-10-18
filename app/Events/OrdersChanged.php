<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Un pedido se creó o cambió de paso, venga de la caja, la web o Telegram: Pedidos y la cocina se vuelven a leer. */
class OrdersChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
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
        return 'changed';
    }

    /** Solo avisa que algo cambió: las pantallas vuelven a consultar. */
    public function broadcastWith(): array
    {
        return ['id' => $this->order->id];
    }
}
