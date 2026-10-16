<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, config('app.timezone'));
    }

    public function test_by_default_it_opens_thursday_to_sunday_from_8_pm_to_midnight(): void
    {
        // 2026-10-15 es jueves.
        $this->assertFalse(Store::isOpen($this->at('2026-10-15 19:59')));
        $this->assertTrue(Store::isOpen($this->at('2026-10-15 20:00')));
        $this->assertTrue(Store::isOpen($this->at('2026-10-15 23:59')));
        $this->assertFalse(Store::isOpen($this->at('2026-10-16 00:00')), 'a medianoche cierra');
        $this->assertTrue(Store::isOpen($this->at('2026-10-17 21:30')), 'sábado');
        $this->assertTrue(Store::isOpen($this->at('2026-10-18 23:59')), 'domingo');
        $this->assertFalse(Store::isOpen($this->at('2026-10-19 00:00')));
        $this->assertFalse(Store::isOpen($this->at('2026-10-19 21:00')), 'lunes');
        $this->assertFalse(Store::isOpen($this->at('2026-10-14 21:00')), 'miércoles');
    }

    public function test_a_closing_time_after_midnight_belongs_to_the_day_that_opened(): void
    {
        $schedule = Store::schedule();
        $schedule['fri'] = ['open' => true, 'from' => '18:00', 'to' => '02:00'];
        Store::saveSchedule($schedule);
        Setting::flush();

        $this->assertTrue(Store::isOpen($this->at('2026-10-17 01:30')), 'sábado de madrugada sigue el viernes');
        $this->assertFalse(Store::isOpen($this->at('2026-10-17 02:00')));
        $this->assertTrue(Store::isOpen($this->at('2026-10-16 18:00')));
    }

    public function test_the_taquero_can_force_it_open_or_closed_regardless_of_the_schedule(): void
    {
        Setting::put('store_mode', 'closed');
        $this->assertFalse(Store::isOpen($this->at('2026-10-15 21:00')));
        $status = Store::status($this->at('2026-10-15 21:00'));
        $this->assertFalse($status['open']);
        $this->assertTrue($status['manual']);

        Setting::put('store_mode', 'open');
        $this->assertTrue(Store::isOpen($this->at('2026-10-19 12:00')));

        Setting::put('store_mode', 'cualquier cosa');
        $this->assertSame('auto', Store::mode());
        $this->assertFalse(Store::isOpen($this->at('2026-10-19 12:00')));
    }

    public function test_status_tells_when_it_closes_and_when_it_opens_next(): void
    {
        $open = Store::status($this->at('2026-10-15 21:00'));
        $this->assertTrue($open['open']);
        $this->assertSame('2026-10-16 00:00', $open['closes_at']->format('Y-m-d H:i'));
        $this->assertNull($open['opens_at']);

        $closed = Store::status($this->at('2026-10-19 12:00'));
        $this->assertFalse($closed['open']);
        $this->assertSame('2026-10-22 20:00', $closed['opens_at']->format('Y-m-d H:i'));
    }

    public function test_the_special_message_is_kept_for_the_customers(): void
    {
        Setting::put('store_mode', 'closed');
        Setting::put('store_message', 'Cerrado por mal clima');

        $this->assertSame('Cerrado por mal clima', Store::status($this->at('2026-10-15 21:00'))['message']);
    }

    public function test_open_and_online_orders_are_separate_things(): void
    {
        $now = $this->at('2026-10-15 21:00');

        $this->assertSame(['ok' => false, 'reason' => 'online_off'], Store::canOrderOnline($now), 'abierta pero con pedidos en línea apagados');
        $this->assertTrue(Store::isOpen($now));

        Setting::put('online_enabled', '1');
        $this->assertSame(['ok' => true, 'reason' => null], Store::canOrderOnline($now));
        $this->assertSame(['ok' => false, 'reason' => 'closed'], Store::canOrderOnline($this->at('2026-10-19 21:00')));

        Setting::put('store_mode', 'closed');
        $this->assertSame(['ok' => false, 'reason' => 'closed'], Store::canOrderOnline($now));
    }

    public function test_delivery_is_off_by_default_with_the_customer_message(): void
    {
        $this->assertFalse(Store::deliveryEnabled());
        $this->assertSame('La taquería no está aceptando órdenes a domicilio, solo para pasar a recoger.', Store::deliveryOffMessage());

        Setting::put('online_delivery', '1');
        Setting::put('online_delivery_message', 'Hoy no hay repartidor');
        $this->assertTrue(Store::deliveryEnabled());
        $this->assertSame('Hoy no hay repartidor', Store::deliveryOffMessage());
    }

    public function test_the_schedule_summary_groups_consecutive_days(): void
    {
        $this->assertSame('Jueves a domingo, de 8:00 pm a 12:00 am', Store::summary());

        $schedule = Store::schedule();
        $schedule['sat']['from'] = '17:00';
        Store::saveSchedule($schedule);
        Setting::flush();
        $this->assertSame('Jueves a viernes, de 8:00 pm a 12:00 am; Sábado, de 5:00 pm a 12:00 am; Domingo, de 8:00 pm a 12:00 am', Store::summary());

        Store::saveSchedule(collect(Store::DAYS)->map(fn () => ['open' => false, 'from' => '20:00', 'to' => '00:00'])->all());
        Setting::flush();
        $this->assertSame('Sin horario', Store::summary());
    }

    public function test_a_corrupt_schedule_falls_back_to_the_default(): void
    {
        Setting::put('store_schedule', '{"thu": "x", "fri": {"open": true, "from": "25:99", "to": "00:00"}}');

        $this->assertSame(Store::defaultSchedule(), Store::schedule());
    }

    public function test_mercado_pago_secrets_are_stored_encrypted(): void
    {
        Store::putSecret('mp_access_token', 'APP_USR-123');
        Setting::put('mp_enabled', '1');
        Setting::put('mp_mode', 'live');

        $this->assertNotSame('APP_USR-123', Setting::value('mp_access_token'));
        $mp = Store::mercadoPago();
        $this->assertSame('APP_USR-123', $mp['access_token']);
        $this->assertTrue($mp['enabled']);
        $this->assertSame('live', $mp['mode']);
        $this->assertNull($mp['webhook_secret']);

        Setting::put('mp_access_token', 'texto-que-no-esta-cifrado');
        $this->assertNull(Store::secret('mp_access_token'));
    }
}
