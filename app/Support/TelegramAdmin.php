<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Setting;

/** El panel del taquero dentro del bot: pedidos, cobro, tienda y resumen, solo para los chats vinculados. */
class TelegramAdmin
{
    /** Botones del panel y de la tienda: no llevan pedido. */
    public const PANEL_ACTIONS = ['adm', 'act', 'req', 'hoy', 'tnd', 'tgo', 'tgd', 'tma', 'tmo', 'tmc'];

    /** Botones de un pedido ya aceptado. */
    public const ORDER_ACTIONS = ['rdy', 'dsp', 'dlv', 'und', 'cnc', 'cnk', 'pay', 'pym'];

    /** Máximo de pedidos que se listan de un jalón: el resto se ve en Pedidos del sistema. */
    public const LIST_LIMIT = 10;

    public static function handles(string $action): bool
    {
        return in_array($action, [...self::PANEL_ACTIONS, ...self::ORDER_ACTIONS], true);
    }

    // ---- Panel ----

    public static function panel(int $chatId, ?int $messageId = null): void
    {
        $store = Store::status();
        $lines = [
            '<b>Panel del taquero</b>',
            ($store['open'] ? 'Abierta' : 'Cerrada').'. Pedidos en línea '.(Store::onlineEnabled() ? 'activados' : 'apagados').'.',
            '',
            'Por aceptar: '.Order::requestedCount(),
            'Activos: '.self::activeQuery()->count(),
        ];

        self::show($chatId, $messageId, implode("\n", $lines), ['inline_keyboard' => [
            [['text' => 'Por aceptar ('.Order::requestedCount().')', 'callback_data' => 'req'], ['text' => 'Activos ('.self::activeQuery()->count().')', 'callback_data' => 'act']],
            [['text' => 'Resumen de hoy', 'callback_data' => 'hoy'], ['text' => 'Tienda', 'callback_data' => 'tnd']],
            [['text' => 'Ver como cliente', 'callback_data' => 'cv']],
        ]]);
    }

    public static function help(int $chatId): void
    {
        Telegram::send($chatId, "Como taquero puedes:\n/panel para ver el panel\n/pendientes para los pedidos por aceptar\n/activos para los que faltan por preparar, cobrar o entregar\n/hoy para el resumen de ventas\n/tienda para abrir, cerrar y apagar pedidos en línea\n/cliente para ver el bot como lo ve un cliente\n/desvincular para dejar de recibir pedidos en este chat");
    }

    /** Pedidos por aceptar, cada uno con sus botones. */
    public static function requested(int $chatId): void
    {
        $orders = Order::requested()->with('items')->orderBy('created_at')->limit(self::LIST_LIMIT)->get();

        if ($orders->isEmpty()) {
            Telegram::send($chatId, 'No hay pedidos esperando respuesta.');

            return;
        }

        $orders->each(fn (Order $order) => TelegramNotifier::sendStaffNotice($order, $chatId));
    }

    /** Pedidos aceptados que falta preparar, cobrar o entregar, cada uno con sus botones. */
    public static function active(int $chatId): void
    {
        $total = self::activeQuery()->count();

        if ($total === 0) {
            Telegram::send($chatId, 'No hay pedidos activos.');

            return;
        }

        $orders = self::activeQuery()->with('items')->orderByRaw('COALESCE(pickup_at, sold_at)')->limit(self::LIST_LIMIT)->get();

        Telegram::send($chatId, '<b>Pedidos activos: '.$total.'</b>'.($total > $orders->count() ? "\nSe muestran los primeros ".$orders->count().'; el resto está en Pedidos del sistema.' : ''));
        $orders->each(fn (Order $order) => TelegramNotifier::sendStaffNotice($order, $chatId));
    }

    public static function today(int $chatId): void
    {
        Telegram::send($chatId, TelegramNotifier::summaryText());
    }

    // ---- Tienda ----

    public static function store(int $chatId, ?int $messageId = null): void
    {
        $status = Store::status();
        $mode = Store::mode();
        $lines = [
            '<b>Tienda</b>',
            $status['open'] ? 'Abierta'.($status['closes_at'] ? ' hasta las '.Store::label($status['closes_at']->format('H:i')) : '').'.' : 'Cerrada.',
            match ($mode) {
                'open' => 'Quedó abierta sin importar el horario.',
                'closed' => 'Quedó cerrada sin importar el horario.',
                default => 'Sigue el horario: '.Telegram::esc(Store::summary()).'.',
            },
            'Pedidos en línea: '.(Store::onlineEnabled() ? 'activados' : 'apagados').'.',
            'Domicilio: '.(Store::deliveryEnabled() ? 'disponible' : 'no disponible').'.',
        ];

        $modes = array_filter([
            $mode !== 'open' ? ['text' => 'Abrir ahora', 'callback_data' => 'tmo'] : null,
            $mode !== 'closed' ? ['text' => 'Cerrar ahora', 'callback_data' => 'tmc'] : null,
            $mode !== 'auto' ? ['text' => 'Seguir el horario', 'callback_data' => 'tma'] : null,
        ]);

        self::show($chatId, $messageId, implode("\n", $lines), ['inline_keyboard' => [
            [['text' => Store::onlineEnabled() ? 'Apagar pedidos en línea' : 'Activar pedidos en línea', 'callback_data' => 'tgo']],
            [['text' => Store::deliveryEnabled() ? 'Quitar domicilio' : 'Activar domicilio', 'callback_data' => 'tgd']],
            array_values($modes),
            [['text' => 'Volver al panel', 'callback_data' => 'adm']],
        ]]);
    }

    /** Cambia un ajuste de la tienda y la muestra actualizada. Devuelve lo que se le dice al taquero. */
    protected static function changeStore(string $action): string
    {
        switch ($action) {
            case 'tgo':
                $on = ! Store::onlineEnabled();
                Setting::put('online_enabled', $on ? '1' : '0');

                return $on ? 'Pedidos en línea activados.' : 'Pedidos en línea apagados.';

            case 'tgd':
                $on = ! Store::deliveryEnabled();
                Setting::put('online_delivery', $on ? '1' : '0');

                return $on ? 'Domicilio disponible.' : 'Sin domicilio, solo recoger.';

            default:
                $mode = ['tma' => 'auto', 'tmo' => 'open', 'tmc' => 'closed'][$action];
                Setting::put('store_mode', $mode === 'auto' ? null : $mode);

                return match ($mode) {
                    'open' => 'Quedó abierta, sin importar el horario.',
                    'closed' => 'Quedó cerrada, sin importar el horario.',
                    default => 'Sigue el horario.',
                };
        }
    }

    // ---- Botones ----

    /** Un botón del panel de un chat del taquero. El panel y la vista de cliente los cambia TelegramBot. */
    public static function panelAction(string $id, int $chatId, ?int $messageId, string $action): void
    {
        if (! Telegram::isStaff($chatId)) {
            Telegram::answerCallback($id, 'Este chat no es del taquero.', true);

            return;
        }

        if (str_starts_with($action, 'tg') || str_starts_with($action, 'tm')) {
            Telegram::answerCallback($id, self::changeStore($action));
            self::store($chatId, $messageId);

            return;
        }

        Telegram::answerCallback($id);

        match ($action) {
            'adm' => self::panel($chatId, $messageId),
            'tnd' => self::store($chatId, $messageId),
            'act' => self::active($chatId),
            'req' => self::requested($chatId),
            'hoy' => self::today($chatId),
        };
    }

    /** Un botón de un pedido: Listo, En camino, Entregado, Cobrar, Cancelar. */
    public static function orderAction(string $id, int $chatId, ?int $messageId, string $action, Order $order, ?int $option): void
    {
        if (! Telegram::isStaff($chatId)) {
            Telegram::answerCallback($id, 'Este chat no puede administrar pedidos.', true);

            return;
        }

        if ($action === 'cnc' || $action === 'pay') {
            self::ask($id, $chatId, $messageId, $action, $order);

            return;
        }

        $done = match ($action) {
            'rdy' => OrderFlow::markReady($order->id) ? 'Listo' : null,
            'dsp' => OrderFlow::dispatch($order->id) ? 'En camino' : null,
            'dlv' => OrderFlow::deliver($order->id) ? 'Entregado' : null,
            'und' => OrderFlow::undeliver($order->id) ? 'Entrega deshecha' : null,
            'cnk' => OrderFlow::cancel($order->id) ? 'Pedido cancelado' : null,
            'pym' => ($method = PaymentMethod::simple()[$option ?? -1] ?? null) && OrderFlow::charge($order->id, $method) ? 'Cobrado en '.mb_strtolower($method->label()) : null,
        };

        if ($done !== null) {
            Telegram::answerCallback($id, $done);

            return;
        }

        Telegram::answerCallback($id, 'El pedido cambió y eso ya no se puede. Lo actualizo.', true);
        self::refresh($chatId, $messageId, $order->fresh('items') ?? $order);
    }

    /** Pide confirmar (cancelar) o elegir cómo se paga (cobrar) en el mismo mensaje del pedido. */
    protected static function ask(string $id, int $chatId, ?int $messageId, string $action, Order $order): void
    {
        if ($order->status !== OrderStatus::Open) {
            Telegram::answerCallback($id, 'Ese pedido ya se cobró o se canceló.', true);
            self::refresh($chatId, $messageId, $order);

            return;
        }

        Telegram::answerCallback($id);

        if (! $messageId) {
            return;
        }

        $back = [['text' => 'Volver', 'callback_data' => 'bk:'.$order->id]];

        if ($action === 'cnc') {
            Telegram::edit($chatId, $messageId, TelegramNotifier::staffText($order)."\n\n<b>¿Cancelar este pedido?</b>", ['inline_keyboard' => [
                [['text' => 'Sí, cancelar', 'callback_data' => 'cnk:'.$order->id]],
                $back,
            ]]);

            return;
        }

        $methods = collect(PaymentMethod::simple())->map(fn (PaymentMethod $method, int $i) => ['text' => $method->label(), 'callback_data' => 'pym:'.$order->id.':'.$i])->all();

        Telegram::edit($chatId, $messageId, TelegramNotifier::staffText($order)."\n\n<b>¿Cómo pagó?</b>", ['inline_keyboard' => [$methods, $back]]);
    }

    /** Vuelve a poner el mensaje del pedido como está ahora, con sus botones. */
    public static function refresh(int $chatId, ?int $messageId, Order $order): void
    {
        if ($messageId) {
            Telegram::edit($chatId, $messageId, TelegramNotifier::staffText($order), TelegramNotifier::staffKeyboard($order));
        }
    }

    // ---- Ayudas ----

    /** Pedidos aceptados de hoy en adelante que falta preparar, cobrar o entregar. */
    protected static function activeQuery()
    {
        return Order::pending()->dueBetween(BusinessDay::startOfToday());
    }

    /** @param  array<string, mixed>  $markup */
    protected static function show(int $chatId, ?int $messageId, string $text, array $markup): void
    {
        if ($messageId) {
            Telegram::edit($chatId, $messageId, $text, $markup);

            return;
        }

        Telegram::send($chatId, $text, $markup);
    }
}
