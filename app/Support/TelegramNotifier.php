<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Avisos del negocio por Telegram: pedidos nuevos al taquero, cambios al cliente, recordatorios y resumen. */
class TelegramNotifier
{
    /** Un pedido en línea nuevo: al taquero con botones para decidir, y al cliente si pidió desde Telegram. */
    public static function placed(Order $order): void
    {
        if (! Telegram::enabled()) {
            return;
        }

        $order = $order->fresh('items') ?? $order;

        foreach (Telegram::staffChats() as $chat) {
            self::sendStaffNotice($order, (int) $chat['id']);
        }

        if ($order->telegram_chat_id) {
            Telegram::send($order->telegram_chat_id, 'Recibimos tu pedido, '.Telegram::esc($order->label).".\nTotal: ".money($order->total).".\nEsperando a que la taquería lo acepte; te avisamos por aquí.\n\nSeguimiento: ".route('pedido', $order->public_token), [
                'inline_keyboard' => [[['text' => 'Cancelar pedido', 'callback_data' => 'can:'.$order->id]]],
            ]);
            Cache::put('telegram:stage:'.$order->id, 'requested', now()->addDays(7));
        }
    }

    /** El pedido cambió (aceptado, rechazado, listo, en camino, entregado): se actualiza el mensaje del taquero y se avisa al cliente. */
    public static function updated(Order $order): void
    {
        if (! Telegram::enabled()) {
            return;
        }

        $order = $order->fresh('items') ?? $order;

        self::refreshStaff($order);

        if (! $order->telegram_chat_id) {
            return;
        }

        $stage = $order->customerStage();

        // Solo se avisa de cada paso una vez, y el de "recibido" ya se mandó al hacer el pedido.
        if ($stage['key'] === 'requested' || Cache::get('telegram:stage:'.$order->id) === $stage['key']) {
            return;
        }

        Cache::put('telegram:stage:'.$order->id, $stage['key'], now()->addDays(7));
        $number = $order->daily_number && $stage['key'] === 'preparing' ? "\nTu número de pedido es el #".$order->daily_number.'.' : '';

        Telegram::send($order->telegram_chat_id, '<b>'.Telegram::esc($stage['title']).'</b>'."\n".Telegram::esc($stage['text']).$number);
    }

    /** Manda al chat del taquero el pedido por aceptar, con sus botones, y guarda el mensaje para poder editarlo. */
    public static function sendStaffNotice(Order $order, int $chatId): void
    {
        $messageId = Telegram::send($chatId, self::staffText($order), self::staffKeyboard($order));

        if ($messageId) {
            DB::table('telegram_notices')->updateOrInsert(['order_id' => $order->id, 'chat_id' => $chatId], ['message_id' => $messageId, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    /** Pone en los mensajes que ya recibió el taquero cómo terminó el pedido y quita los botones. */
    public static function refreshStaff(Order $order): void
    {
        foreach (DB::table('telegram_notices')->where('order_id', $order->id)->get() as $notice) {
            Telegram::edit((int) $notice->chat_id, (int) $notice->message_id, self::staffText($order), self::staffKeyboard($order));
        }
    }

    /** Recordatorio al taquero de un pedido que lleva rato esperando. */
    public static function remind(Order $order): void
    {
        if (! Telegram::enabled() || $order->status !== OrderStatus::Requested) {
            return;
        }

        $minutes = max(1, (int) $order->created_at->diffInMinutes(now()));

        foreach (Telegram::staffChats() as $chat) {
            Telegram::send((int) $chat['id'], '<b>Pedido esperando</b>'."\n".Telegram::esc($order->label).' lleva '.$minutes.' minutos sin respuesta. Total: '.money($order->total).'.', self::staffKeyboard($order));
        }
    }

    /** Resumen del día para el taquero al cerrar. */
    public static function summary(): void
    {
        if (! Telegram::enabled() || Telegram::staffChats() === []) {
            return;
        }

        $day = BusinessDay::of(now());
        $paid = Order::paidBetween(BusinessDay::start($day), BusinessDay::end($day))->get();
        $online = $paid->where('source', 'online');
        $unpaid = Order::where('status', OrderStatus::Open)->count();

        $text = '<b>Resumen del día</b>'."\n"
            .$paid->count().' ventas por '.money($paid->sum('total'))."\n"
            .'En línea: '.$online->count().' por '.money($online->sum('total'))
            .($unpaid ? "\n".$unpaid.' pedidos todavía sin cobrar.' : '')
            ."\nEl detalle y el corte de caja están en el sistema.";

        foreach (Telegram::staffChats() as $chat) {
            Telegram::send((int) $chat['id'], $text);
        }
    }

    public static function staffText(Order $order): string
    {
        $lines = ['<b>Pedido en línea de '.Telegram::esc($order->label).'</b>'];
        $lines[] = $order->isDelivery() ? 'A domicilio: '.Telegram::esc($order->delivery_address).($order->delivery_notes ? ' ('.Telegram::esc($order->delivery_notes).')' : '') : 'Pasa a recoger';
        $lines[] = 'Teléfono: '.Telegram::esc($order->customer_phone);
        $lines[] = '';

        foreach ($order->items as $item) {
            $details = array_filter([
                ...collect($item->choices())->pluck('name')->all(),
                ...array_map(fn ($n) => 'sin '.mb_strtolower($n), $item->withoutNames()),
                ...collect($item->extras())->map(fn ($e) => '+ '.$e['name'])->all(),
                $item->notes ? '"'.$item->notes.'"' : null,
            ]);
            $lines[] = $item->quantity.' x '.Telegram::esc($item->product_name).($details ? ' ('.Telegram::esc(implode(', ', $details)).')' : '');
        }

        if ($order->notes) {
            $lines[] = '';
            $lines[] = 'Nota: '.Telegram::esc($order->notes);
        }

        $lines[] = '';
        $lines[] = 'Total: '.money($order->total);

        if ($footer = self::footer($order)) {
            $lines[] = '';
            $lines[] = '<b>'.Telegram::esc($footer).'</b>';
        }

        return implode("\n", $lines);
    }

    /** Cómo va el pedido, para el pie del mensaje del taquero; null mientras sigue por aceptar. */
    public static function footer(Order $order): ?string
    {
        return match (true) {
            $order->status === OrderStatus::Requested => null,
            $order->status === OrderStatus::Cancelled && $order->cancel_reason === Order::CUSTOMER_CANCELLED => 'Cancelado por el cliente',
            $order->status === OrderStatus::Cancelled && ! $order->accepted_at && $order->cancel_reason === 'Nadie respondió a tiempo' => 'Caducó sin respuesta',
            $order->status === OrderStatus::Cancelled && ! $order->accepted_at => 'Rechazado: '.($order->cancel_reason ?: 'sin motivo'),
            $order->status === OrderStatus::Cancelled => 'Cancelado',
            $order->delivered_at !== null => 'Entregado (#'.$order->daily_number.')',
            default => 'Aceptado: es el #'.$order->daily_number,
        };
    }

    /** @return array{inline_keyboard: array<int, array<int, array<string, string>>>}|null */
    public static function staffKeyboard(Order $order): ?array
    {
        return $order->status === OrderStatus::Requested
            ? ['inline_keyboard' => [[['text' => 'Aceptar', 'callback_data' => 'acc:'.$order->id], ['text' => 'Rechazar', 'callback_data' => 'rej:'.$order->id]]]]
            : null;
    }
}
