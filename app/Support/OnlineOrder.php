<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Events\OnlineOrderPlaced;
use App\Events\OnlineOrderUpdated;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos que hacen los clientes desde la página pública. Todos llegan "Por aceptar":
 * el taquero decide si los acepta (entran a Pedidos y Cocina) o los rechaza.
 */
class OnlineOrder
{
    public const MAX_LINES = 30;

    public const MAX_QUANTITY = 20;

    /** Pedidos nuevos por equipo en 10 minutos, y por teléfono en una hora. */
    public const LIMIT_PER_IP = 5;

    public const LIMIT_PER_PHONE = 3;

    /** Motivos que el taquero elige con un toque al rechazar. */
    public const REJECT_REASONS = [
        'Se acabó algún producto',
        'Ya no alcanzamos a atenderlo',
        'No llegamos a esa zona',
        'No pudimos confirmar tu pedido',
    ];

    /**
     * Recibe lo que mandó el navegador y crea el pedido "Por aceptar". Todo se vuelve a validar y
     * a calcular aquí: precios, extras, cantidades y el servicio disponible salen de la base y los ajustes.
     *
     * @param  array{token?: string, name?: mixed, phone?: mixed, service?: mixed, address?: mixed, references?: mixed, notes?: mixed, lines?: mixed}  $input
     *
     * @throws ValidationException
     */
    public static function place(array $input, string $ip): Order
    {
        $token = is_string($input['token'] ?? null) && Str::isUuid($input['token']) ? $input['token'] : null;

        if ($token && $existing = Order::where('operation_token', $token)->first()) {
            return $existing;
        }

        self::assertOpen();

        $name = Names::clean(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '');
        $phone = self::normalizePhone(is_scalar($input['phone'] ?? null) ? (string) $input['phone'] : '');
        $service = $input['service'] ?? null;
        $address = Names::clean(is_scalar($input['address'] ?? null) ? (string) $input['address'] : '');
        $references = Names::clean(is_scalar($input['references'] ?? null) ? (string) $input['references'] : '');
        $notes = Names::clean(is_scalar($input['notes'] ?? null) ? (string) $input['notes'] : '');

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $errors['name'] = 'Escribe tu nombre (hasta 60 letras).';
        }
        if ($phone === null) {
            $errors['phone'] = 'Escribe tu teléfono a 10 dígitos.';
        }
        if (! in_array($service, ['togo', 'delivery'], true)) {
            $errors['service'] = 'Elige si pasas a recoger o si es a domicilio.';
        } elseif ($service === 'delivery' && ! Store::deliveryEnabled()) {
            $errors['service'] = Store::deliveryOffMessage();
        } elseif ($service === 'delivery' && ($address === '' || mb_strlen($address) > 255)) {
            $errors['address'] = 'Escribe la dirección de entrega.';
        }
        if (mb_strlen($references) > 160 || mb_strlen($notes) > 160) {
            $errors['notes'] = 'Las notas pueden tener hasta 160 letras.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $built = OrderBuilder::items(self::cleanLines($input['lines'] ?? []));

        if (isset($built['error'])) {
            throw ValidationException::withMessages(['lines' => $built['error']]);
        }
        if ($built['unavailable']) {
            throw ValidationException::withMessages(['lines' => 'Ya no tenemos: '.implode(', ', $built['unavailable']).'. Quítalo de tu pedido.']);
        }
        if ($built['missing']) {
            throw ValidationException::withMessages(['lines' => 'Falta elegir: '.implode(', ', $built['missing']).'.']);
        }
        if ($built['items'] === []) {
            throw ValidationException::withMessages(['lines' => 'Agrega algo a tu pedido.']);
        }

        self::throttle($ip, $phone);

        $order = DB::transaction(function () use ($token, $name, $phone, $service, $address, $references, $notes, $built) {
            $delivery = $service === 'delivery';
            $order = Order::create([
                'source' => 'online',
                'status' => OrderStatus::Requested,
                'operation_token' => $token,
                'public_token' => (string) Str::uuid(),
                'label' => $name,
                'customer_phone' => $phone,
                'service' => $service,
                'delivery_address' => $delivery ? $address : null,
                'delivery_phone' => $delivery ? $phone : null,
                'delivery_notes' => $delivery ? ($references ?: null) : null,
                'notes' => $notes ?: null,
                'packaging_fee' => (float) Setting::value('packaging_fee', '0'),
                'delivery_fee' => $delivery ? (float) Setting::value('delivery_fee', '0') : 0,
                'discount' => 0,
                'tip' => 0,
                'sold_at' => now(),
                'total' => 0,
            ]);

            OrderBuilder::fill($order, $built);

            return $order->fresh(['items']);
        });

        self::broadcast(new OnlineOrderPlaced($order));

        return $order;
    }

    /** El taquero acepta: entra a Pedidos y Cocina como cualquier pedido por cobrar, con su número del día. */
    public static function accept(Order $order): bool
    {
        $accepted = Cache::lock('orders:daily-number', 10)->block(5, fn () => DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->where('status', OrderStatus::Requested)->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            $locked->update([
                'status' => OrderStatus::Open,
                'daily_number' => Order::nextDailyNumber(),
                'accepted_at' => now(),
                'sold_at' => now(),
                'changed_at' => null,
            ]);

            return $locked;
        }));

        return (bool) $accepted;
    }

    /** El taquero rechaza con un motivo que el cliente ve. */
    public static function reject(Order $order, string $reason): bool
    {
        $reason = mb_substr(Names::clean($reason), 0, 120);

        return self::cancelRequested($order, $reason !== '' ? $reason : 'No pudimos atender tu pedido');
    }

    /** El cliente cancela mientras nadie lo ha aceptado. */
    public static function cancelByCustomer(Order $order): bool
    {
        return self::cancelRequested($order, Order::CUSTOMER_CANCELLED);
    }

    protected static function cancelRequested(Order $order, string $reason): bool
    {
        $changed = DB::transaction(function () use ($order, $reason) {
            $locked = Order::whereKey($order->id)->where('status', OrderStatus::Requested)->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            $locked->cancel($reason);

            return $locked;
        });

        return (bool) $changed;
    }

    /** Avisa a la página del cliente que su pedido cambió (para los cambios que se hacen con consultas directas). */
    public static function notifyCustomer(int $id): void
    {
        $order = Order::find($id);

        if ($order?->isOnline() && $order->public_token) {
            self::broadcast(new OnlineOrderUpdated($order));
        }
    }

    /** Avisa a las pantallas abiertas. Si el servidor de tiempo real no responde, el pedido no se pierde. */
    public static function broadcast(object $event): void
    {
        rescue(fn () => event($event), report: true);
    }

    /** Pasa de "(55) 1234 5678" o "+52 55 1234 5678" a los 10 dígitos; null si no se puede. */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^[2-9]\d{9}$/', $digits) ? $digits : null;
    }

    /**
     * Solo producto, cantidad e indicaciones: el cliente no puede mandar cortesías ni precios.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function cleanLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        return collect($lines)->take(self::MAX_LINES)->filter(fn ($l) => is_array($l) && is_numeric($l['product_id'] ?? null))
            ->map(fn (array $l) => [
                'product_id' => (int) $l['product_id'],
                'qty' => max(1, min(self::MAX_QUANTITY, (int) ($l['qty'] ?? 1))),
                'without' => self::ids($l['without'] ?? []),
                'extras' => self::ids($l['extras'] ?? []),
                'choices' => self::ids($l['choices'] ?? []),
                'notes' => is_scalar($l['notes'] ?? null) ? mb_substr(Names::clean((string) $l['notes']), 0, 120) : null,
            ])->values()->all();
    }

    /** @return array<int, int> */
    protected static function ids(mixed $value): array
    {
        return is_array($value) ? collect($value)->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->take(20)->values()->all() : [];
    }

    protected static function assertOpen(): void
    {
        $check = Store::canOrderOnline();

        if ($check['ok']) {
            return;
        }

        throw ValidationException::withMessages(['store' => match ($check['reason']) {
            'online_off' => 'Por ahora no estamos recibiendo pedidos en línea.',
            default => Store::message() ?: 'La taquería está cerrada en este momento.',
        }]);
    }

    protected static function throttle(string $ip, ?string $phone): void
    {
        $limits = [
            'online-order-ip|'.$ip => [self::LIMIT_PER_IP, 600],
            'online-order-phone|'.$phone => [self::LIMIT_PER_PHONE, 3600],
        ];

        foreach ($limits as $key => [$attempts]) {
            if (RateLimiter::tooManyAttempts($key, $attempts)) {
                throw ValidationException::withMessages(['store' => 'Hiciste varios pedidos seguidos. Espera unos minutos e inténtalo de nuevo.']);
            }
        }

        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
