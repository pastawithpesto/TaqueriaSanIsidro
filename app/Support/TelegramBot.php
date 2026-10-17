<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Lo que el bot hace con cada mensaje que le llega: comandos, botones y vincular al taquero. */
class TelegramBot
{
    /** Mensajes por chat y minuto que se atienden; el resto se ignora (un chat no debe poder saturar al bot). */
    public const MESSAGES_PER_MINUTE = 30;

    /** @param  array<string, mixed>  $update  Un "update" de Telegram tal como llega. */
    public static function handle(array $update): void
    {
        if (! Telegram::enabled()) {
            return;
        }

        if (is_array($update['message'] ?? null)) {
            self::message($update['message']);
        } elseif (is_array($update['callback_query'] ?? null)) {
            self::callback($update['callback_query']);
        }
    }

    // ---- Mensajes y comandos ----

    /** @param  array<string, mixed>  $message */
    protected static function message(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';

        if (! is_int($chatId) || $text === '' || self::tooMany($chatId)) {
            return;
        }

        $private = ($message['chat']['type'] ?? '') === 'private';
        $name = trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')) ?: (string) ($message['chat']['title'] ?? 'Chat');

        if (! preg_match('/^\/(\w+)(?:@\w+)?(?:\s+(.*))?$/su', $text, $match)) {
            if ($private) {
                self::help($chatId);
            }

            return;
        }

        $arguments = trim($match[2] ?? '');

        match (Str::lower($match[1])) {
            'start' => self::start($chatId, $private, $name, $arguments),
            'pedido' => self::order($chatId, $private),
            'menu' => self::menu($chatId),
            'horario' => self::schedule($chatId),
            'estado' => self::status($chatId),
            'ayuda', 'help' => self::help($chatId),
            'vincular' => self::link($chatId, $name, $arguments),
            'desvincular' => self::unlink($chatId),
            'pendientes' => self::pending($chatId, $private),
            default => $private ? self::help($chatId) : null,
        };
    }

    protected static function start(int $chatId, bool $private, string $name, string $payload): void
    {
        // Enlace desde la página de seguimiento: "Recibir avisos por Telegram".
        if ($private && preg_match('/^pedido_([0-9a-f-]{36})$/i', $payload, $match)) {
            self::linkOrder($chatId, $match[1]);

            return;
        }

        $first = Telegram::esc(Str::before($name, ' '));
        $store = Store::status();
        $line = $store['open'] ? 'Estamos abiertos' : 'Por ahora estamos cerrados';

        Telegram::send($chatId, 'Hola'.($first !== '' ? ', '.$first : '').'. Bienvenido a '.Telegram::esc(Branding::name()).".\n".$line.'. '.($store['message'] ? Telegram::esc($store['message']).' ' : '')."\nToca Hacer pedido para ver el menú, personalizar tus tacos y pedir.", self::mainKeyboard($private));
    }

    protected static function order(int $chatId, bool $private): void
    {
        $store = self::availability();

        Telegram::send($chatId, ($store ? $store."\n" : '').'Abre el menú para armar tu pedido.', self::mainKeyboard($private));
    }

    protected static function menu(int $chatId): void
    {
        $products = Product::where('active', true)->orderBy('sort_order')->orderBy('name')->get()->groupBy(fn (Product $p) => $p->category ?: 'Menú');

        if ($products->isEmpty()) {
            Telegram::send($chatId, 'Todavía no hay menú publicado.');

            return;
        }

        $chunks = [];
        $current = '';

        foreach ($products as $category => $group) {
            $block = '<b>'.Telegram::esc($category)."</b>\n".$group->map(fn (Product $p) => Telegram::esc($p->name).'  '.money($p->price).($p->isSoldOut() ? ' (agotado hoy)' : ''))->join("\n")."\n\n";

            if (mb_strlen($current.$block) > 3500) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= $block;
        }

        $chunks[] = $current;

        foreach ($chunks as $i => $chunk) {
            Telegram::send($chatId, rtrim($chunk), $i === array_key_last($chunks) ? self::mainKeyboard(true) : null);
        }
    }

    protected static function schedule(int $chatId): void
    {
        $status = Store::status();
        $lines = [$status['open'] ? 'Estamos abiertos'.($status['closes_at'] ? ' hasta las '.Store::label($status['closes_at']->format('H:i')) : '').'.' : 'Estamos cerrados'.($status['opens_at'] ? '. Abrimos el '.mb_strtolower($status['opens_at']->translatedFormat('l')).' a las '.Store::label($status['opens_at']->format('H:i')) : '').'.'];

        if ($status['message']) {
            $lines[] = Telegram::esc($status['message']);
        }

        $lines[] = 'Horario: '.Store::summary().'.';
        $lines[] = Store::onlineEnabled() ? 'Pedidos en línea: activados.' : 'Pedidos en línea: apagados por ahora.';
        $lines[] = Store::deliveryEnabled() ? 'A domicilio: disponible.' : Telegram::esc(Store::deliveryOffMessage());

        Telegram::send($chatId, implode("\n", $lines));
    }

    protected static function status(int $chatId): void
    {
        $order = Order::where('telegram_chat_id', $chatId)->where('source', 'online')->latest('id')->first();

        if (! $order || $order->isTrackingExpired()) {
            Telegram::send($chatId, 'No encuentro un pedido reciente tuyo. Toca Hacer pedido para empezar.', self::mainKeyboard(true));

            return;
        }

        $stage = $order->customerStage();

        Telegram::send($chatId, '<b>'.Telegram::esc($stage['title']).'</b>'."\n".Telegram::esc(Str::of($stage['text'])->replace(' Deja esta página abierta: aquí mismo te avisamos.', ' Te avisamos por aquí.')->toString())."\n\nTotal: ".money($order->total)."\nSeguimiento: ".route('pedido', $order->public_token), $order->status === OrderStatus::Requested ? ['inline_keyboard' => [[['text' => 'Cancelar pedido', 'callback_data' => 'can:'.$order->id]]]] : null);
    }

    protected static function help(int $chatId): void
    {
        $staff = Telegram::isStaff($chatId) ? "\n\nComo taquero, aquí te llegan los pedidos nuevos con botones para aceptarlos o rechazarlos. /pendientes los vuelve a mostrar y /desvincular deja de enviarlos a este chat." : '';

        Telegram::send($chatId, "Esto puedo hacer:\n/pedido para hacer un pedido\n/menu para ver el menú y los precios\n/horario para saber si estamos abiertos\n/estado para ver cómo va tu pedido".$staff, self::mainKeyboard(true));
    }

    protected static function link(int $chatId, string $name, string $code): void
    {
        if (Telegram::isStaff($chatId)) {
            Telegram::send($chatId, 'Este chat ya recibe los pedidos.');

            return;
        }

        if ($code === '' || ! Telegram::consumeLinkCode($chatId, $code, $name)) {
            Telegram::send($chatId, 'Código incorrecto o vencido. Genera uno nuevo en Tienda en línea del sistema y escribe /vincular seguido del código.');

            return;
        }

        Telegram::send($chatId, 'Listo: este chat recibirá los pedidos en línea con botones para aceptarlos o rechazarlos.');

        foreach (Order::requested()->with('items')->orderBy('created_at')->limit(10)->get() as $order) {
            TelegramNotifier::sendStaffNotice($order, $chatId);
        }
    }

    protected static function unlink(int $chatId): void
    {
        if (! Telegram::isStaff($chatId)) {
            return;
        }

        Telegram::removeStaff($chatId);
        Telegram::send($chatId, 'Listo: este chat ya no recibe los pedidos.');
    }

    protected static function pending(int $chatId, bool $private): void
    {
        if (! Telegram::isStaff($chatId)) {
            if ($private) {
                Telegram::send($chatId, 'Ese comando es solo para el taquero.');
            }

            return;
        }

        $orders = Order::requested()->with('items')->orderBy('created_at')->limit(10)->get();

        if ($orders->isEmpty()) {
            Telegram::send($chatId, 'No hay pedidos esperando respuesta.');

            return;
        }

        $orders->each(fn (Order $order) => TelegramNotifier::sendStaffNotice($order, $chatId));
    }

    protected static function linkOrder(int $chatId, string $token): void
    {
        $order = Str::isUuid($token) ? Order::where('public_token', $token)->where('source', 'online')->first() : null;

        if (! $order || $order->isTrackingExpired()) {
            Telegram::send($chatId, 'Ese pedido ya no está disponible.');

            return;
        }

        if ($order->telegram_chat_id && $order->telegram_chat_id !== $chatId) {
            Telegram::send($chatId, 'Ese pedido ya recibe avisos en otro chat.');

            return;
        }

        $order->forceFill(['telegram_chat_id' => $chatId])->saveQuietly();
        Telegram::send($chatId, 'Listo, te avisamos por aquí de tu pedido.');
        self::status($chatId);
    }

    // ---- Botones ----

    /** @param  array<string, mixed>  $query */
    protected static function callback(array $query): void
    {
        $id = (string) ($query['id'] ?? '');
        $chatId = $query['message']['chat']['id'] ?? null;
        $data = is_string($query['data'] ?? null) ? $query['data'] : '';

        if ($id === '' || ! is_int($chatId) || ! preg_match('/^(\w+)(?::(\d{1,12}))?(?::(\d{1,2}))?$/', $data, $match)) {
            return;
        }

        if (self::tooMany($chatId)) {
            Telegram::answerCallback($id);

            return;
        }

        $action = $match[1];
        $orderId = isset($match[2]) ? (int) $match[2] : null;
        $messageId = $query['message']['message_id'] ?? null;

        // Los botones de la bienvenida hacen lo mismo que los comandos.
        if (in_array($action, ['menu', 'horario', 'estado'], true)) {
            Telegram::answerCallback($id);
            self::{$action === 'menu' ? 'menu' : ($action === 'horario' ? 'schedule' : 'status')}($chatId);

            return;
        }

        $order = $orderId ? Order::where('source', 'online')->find($orderId) : null;

        if (! $order) {
            Telegram::answerCallback($id, 'Ese pedido ya no existe.');

            return;
        }

        match ($action) {
            'acc', 'rej', 'rjr', 'bk' => self::staffAction($id, $chatId, $messageId, $action, $order, isset($match[3]) ? (int) $match[3] : null),
            'can' => self::customerCancel($id, $chatId, $order),
            default => Telegram::answerCallback($id),
        };
    }

    protected static function staffAction(string $id, int $chatId, ?int $messageId, string $action, Order $order, ?int $reason): void
    {
        // Solo los chats que el taquero vinculó pueden decidir; el botón de otro chat no sirve aunque conozca el número.
        if (! Telegram::isStaff($chatId)) {
            Telegram::answerCallback($id, 'Este chat no puede responder pedidos.', true);

            return;
        }

        if ($order->status !== OrderStatus::Requested) {
            Telegram::answerCallback($id, 'Ese pedido ya no está por aceptar.');
            TelegramNotifier::refreshStaff($order);

            return;
        }

        switch ($action) {
            case 'acc':
                if (OnlineOrder::accept($order)) {
                    Telegram::answerCallback($id, 'Pedido aceptado');
                } else {
                    Telegram::answerCallback($id, 'Ya no se pudo aceptar: caducó o cambió.', true);
                }
                break;

            case 'rej':
                Telegram::answerCallback($id);
                $rows = collect(OnlineOrder::REJECT_REASONS)->map(fn ($text, $i) => [['text' => $text, 'callback_data' => 'rjr:'.$order->id.':'.$i]])->all();
                $rows[] = [['text' => 'Volver', 'callback_data' => 'bk:'.$order->id]];
                if ($messageId) {
                    Telegram::edit($chatId, $messageId, TelegramNotifier::staffText($order)."\n\n<b>Elige el motivo del rechazo</b>", ['inline_keyboard' => $rows]);
                }
                break;

            case 'rjr':
                $text = $reason !== null ? (OnlineOrder::REJECT_REASONS[$reason] ?? null) : null;
                if ($text !== null && OnlineOrder::reject($order, $text)) {
                    Telegram::answerCallback($id, 'Pedido rechazado');
                } else {
                    Telegram::answerCallback($id, 'No se pudo rechazar.', true);
                }
                break;

            case 'bk':
                Telegram::answerCallback($id);
                if ($messageId) {
                    Telegram::edit($chatId, $messageId, TelegramNotifier::staffText($order), TelegramNotifier::staffKeyboard($order));
                }
                break;
        }
    }

    protected static function customerCancel(string $id, int $chatId, Order $order): void
    {
        // Solo el chat desde el que se hizo el pedido puede cancelarlo.
        if ($order->telegram_chat_id !== $chatId) {
            Telegram::answerCallback($id, 'Ese pedido no es de este chat.', true);

            return;
        }

        if (OnlineOrder::cancelByCustomer($order)) {
            Telegram::answerCallback($id, 'Pedido cancelado');
        } else {
            Telegram::answerCallback($id, 'Ya no se puede cancelar: la taquería ya respondió.', true);
        }
    }

    // ---- Ayudas ----

    /** @return array<string, mixed> */
    protected static function mainKeyboard(bool $private): array
    {
        $order = $private
            ? ['text' => 'Hacer pedido', 'web_app' => ['url' => Telegram::miniAppUrl()]]
            : ['text' => 'Hacer pedido', 'url' => url('/')];

        return ['inline_keyboard' => [
            [$order],
            [['text' => 'Menú', 'callback_data' => 'menu'], ['text' => 'Horario', 'callback_data' => 'horario'], ['text' => 'Mi pedido', 'callback_data' => 'estado']],
        ]];
    }

    /** Aviso corto si hoy no se puede pedir: cerrado, pedidos apagados o mensaje especial. */
    protected static function availability(): ?string
    {
        $check = Store::canOrderOnline();

        if ($check['ok']) {
            return Store::message() ? Telegram::esc(Store::message()) : null;
        }

        return $check['reason'] === 'online_off'
            ? 'Por ahora no estamos recibiendo pedidos en línea, pero puedes ver el menú.'
            : 'Estamos cerrados en este momento'.(Store::message() ? ': '.Telegram::esc(Store::message()) : '.').' Puedes ver el menú.';
    }

    protected static function tooMany(int $chatId): bool
    {
        $key = 'telegram-chat|'.$chatId;

        if (RateLimiter::tooManyAttempts($key, self::MESSAGES_PER_MINUTE)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
