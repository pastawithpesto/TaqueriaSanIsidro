<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_page_requires_login_and_shows_the_current_status(): void
    {
        auth()->logout();
        $this->get(route('tienda'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('tienda'))->assertOk()->assertSee('Tienda en línea')->assertSee('Jueves a domingo, de 8:00 pm a 12:00 am');
    }

    public function test_the_taquero_sets_the_store_open_or_closed_by_hand_and_back_to_the_schedule(): void
    {
        $page = Livewire::test('pages::tienda');

        $page->call('setMode', 'closed')->assertSet('mode', 'closed');
        $this->assertSame('closed', Store::mode());

        $page->call('setMode', 'open');
        $this->assertSame('open', Store::mode());

        $page->call('setMode', 'auto');
        $this->assertSame('auto', Store::mode());
        $this->assertNull(Setting::where('key', 'store_mode')->value('value'));

        $page->call('setMode', 'cualquiera');
        $this->assertSame('auto', Store::mode());
    }

    public function test_the_special_message_is_saved_and_cleared(): void
    {
        Livewire::test('pages::tienda')
            ->set('notice', '  Cerrado por mal clima  ')
            ->call('saveMessage')
            ->assertHasNoErrors();
        $this->assertSame('Cerrado por mal clima', Store::message());

        Livewire::test('pages::tienda')->set('notice', '')->call('saveMessage');
        $this->assertNull(Store::message());

        Livewire::test('pages::tienda')->set('notice', str_repeat('a', 201))->call('saveMessage')->assertHasErrors('notice');
    }

    public function test_online_orders_and_delivery_are_toggled_separately_from_being_open(): void
    {
        $page = Livewire::test('pages::tienda')->assertSet('online', false)->assertSet('delivery', false);

        $page->call('toggleOnline')->assertSet('online', true);
        $this->assertTrue(Store::onlineEnabled());
        $this->assertFalse(Store::deliveryEnabled());
        $this->assertSame('auto', Store::mode());

        $page->call('toggleDelivery')->assertSet('delivery', true);
        $this->assertTrue(Store::deliveryEnabled());

        $page->call('toggleOnline');
        $this->assertFalse(Store::onlineEnabled());
        $this->assertTrue(Store::deliveryEnabled());
    }

    public function test_the_delivery_off_message_can_be_customised(): void
    {
        Livewire::test('pages::tienda')->set('deliveryMessage', 'Hoy no hay repartidor')->call('saveDeliveryMessage')->assertHasNoErrors();

        $this->assertSame('Hoy no hay repartidor', Store::deliveryOffMessage());
    }

    public function test_the_schedule_is_saved_per_day_and_validated(): void
    {
        $page = Livewire::test('pages::tienda');
        $schedule = $page->get('schedule');
        $schedule['mon'] = ['open' => true, 'from' => '09:00', 'to' => '14:00'];
        $schedule['thu']['open'] = false;

        $page->set('schedule', $schedule)->call('saveSchedule')->assertHasNoErrors();

        $saved = Store::schedule();
        $this->assertTrue($saved['mon']['open']);
        $this->assertSame('09:00', $saved['mon']['from']);
        $this->assertFalse($saved['thu']['open']);

        $schedule['tue'] = ['open' => true, 'from' => '25:00', 'to' => ''];
        $page->set('schedule', $schedule)->call('saveSchedule')->assertHasErrors(['schedule.tue.from', 'schedule.tue.to']);
        $this->assertFalse(Store::schedule()['tue']['open']);
    }

    public function test_mercado_pago_credentials_are_stored_encrypted_and_not_shown_again(): void
    {
        $page = Livewire::test('pages::tienda')
            ->set('mpPublicKey', 'TEST-pub-123')
            ->set('mpAccessToken', 'TEST-token-abc')
            ->set('mpWebhookSecret', 'whsec')
            ->set('mpEnabled', true)
            ->set('mpRequired', true)
            ->call('saveMercadoPago')
            ->assertHasNoErrors()
            ->assertSet('mpAccessToken', '')
            ->assertSet('mpWebhookSecret', '')
            ->assertDontSee('TEST-token-abc');

        $mp = Store::mercadoPago();
        $this->assertSame('TEST-token-abc', $mp['access_token']);
        $this->assertSame('whsec', $mp['webhook_secret']);
        $this->assertSame('TEST-pub-123', $mp['public_key']);
        $this->assertTrue($mp['enabled']);
        $this->assertTrue($mp['required']);
        $this->assertNotSame('TEST-token-abc', Setting::where('key', 'mp_access_token')->value('value'));

        // Guardar de nuevo sin escribir el token lo conserva.
        $page->set('mpPublicKey', 'TEST-pub-999')->call('saveMercadoPago');
        $this->assertSame('TEST-token-abc', Store::mercadoPago()['access_token']);
        $this->assertSame('TEST-pub-999', Store::mercadoPago()['public_key']);
    }

    public function test_mercado_pago_cannot_be_turned_on_without_its_keys(): void
    {
        Livewire::test('pages::tienda')->set('mpEnabled', true)->call('saveMercadoPago')->assertHasErrors('mpEnabled');
        $this->assertFalse(Store::mercadoPago()['enabled']);

        Livewire::test('pages::tienda')->set('mpPublicKey', 'TEST-pub')->set('mpEnabled', true)->call('saveMercadoPago')->assertHasErrors('mpEnabled');
        $this->assertFalse(Store::mercadoPago()['enabled']);
    }

    public function test_the_credentials_can_be_removed(): void
    {
        Store::putSecret('mp_access_token', 'x');
        Setting::put('mp_public_key', 'k');
        Setting::put('mp_enabled', '1');

        Livewire::test('pages::tienda')->call('removeMercadoPagoKeys');

        $mp = Store::mercadoPago();
        $this->assertNull($mp['access_token']);
        $this->assertNull($mp['public_key']);
        $this->assertFalse($mp['enabled']);
    }
}
