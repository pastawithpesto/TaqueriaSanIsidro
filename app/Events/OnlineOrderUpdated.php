<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** El pedido de un cliente cambió (aceptado, rechazado, listo, en camino, entregado): su página de seguimiento se actualiza. */
class OnlineOrderUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public Order $order) {}

    /** El nombre del canal lleva el enlace secreto del pedido: solo quien lo tiene se entera. */
    public function broadcastOn(): array
    {
        return [new Channel('order.'.$this->order->public_token)];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    /** Solo avisa que algo cambió, sin datos del pedido: la página lo vuelve a leer. */
    public function broadcastWith(): array
    {
        return ['version' => (int) $this->order->version];
    }
}
