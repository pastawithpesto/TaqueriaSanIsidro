<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flush();
        Storage::fake('local');
    }

    public function test_settings_page_requires_login(): void
    {
        $this->get('/ajustes')->assertRedirect('/login');
    }

    public function test_defaults_are_used_when_nothing_is_configured(): void
    {
        $this->assertSame('Taquería San Isidro', Branding::name());
        $this->assertFalse(Branding::hasCustomLogo());

        $this->get('/brand/logo-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/brand/logo-999.png')->assertNotFound();
    }

    public function test_manifest_is_public_and_follows_the_settings(): void
    {
        Setting::put('business_name', 'Tacos El Güero');
        Setting::put('business_short_name', 'El Güero');

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Tacos El Güero')
            ->assertJsonPath('short_name', 'El Güero')
            ->assertJsonCount(2, 'icons');
    }

    public function test_business_name_is_saved_and_shown(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::ajustes')
            ->set('name', 'Tacos El Güero')
            ->set('short_name', 'El Güero')
            ->call('saveBusiness')
            ->assertHasNoErrors();

        Setting::flush();
        $this->assertSame('Tacos El Güero', Branding::name());
        $this->get('/')->assertSee('Tacos El Güero');
    }

    public function test_name_is_required(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::ajustes')
            ->set('name', '')
            ->call('saveBusiness')
            ->assertHasErrors('name');
    }

    public function test_uploaded_logo_is_cropped_to_square_and_resized(): void
    {
        $this->actingAs(User::factory()->create());
        $before = Branding::logoUrl(192);

        Livewire::test('pages::ajustes')
            ->set('logo', UploadedFile::fake()->image('logo.png', 900, 600))
            ->call('saveLogo')
            ->assertHasNoErrors();

        Setting::flush();
        $this->assertTrue(Branding::hasCustomLogo());
        $this->assertNotSame($before, Branding::logoUrl(192));

        foreach (Branding::SIZES as $size) {
            $path = Branding::path($size);
            $this->assertStringContainsString('branding', $path);
            $this->assertSame([$size, $size], array_slice(getimagesize($path), 0, 2));
        }
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::ajustes')
            ->set('logo', UploadedFile::fake()->create('notas.pdf', 100, 'application/pdf'))
            ->assertHasErrors('logo');

        $this->assertFalse(Branding::hasCustomLogo());
    }

    public function test_logo_can_be_restored_to_the_original(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::ajustes')
            ->set('logo', UploadedFile::fake()->image('logo.png', 400, 400))
            ->call('saveLogo')
            ->call('resetLogo');

        Setting::flush();
        $this->assertFalse(Branding::hasCustomLogo());
        $this->assertStringContainsString('images/brand', Branding::path(192));
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'actual-123']);
        $this->actingAs($user);

        Livewire::test('pages::ajustes')
            ->set('current_password', 'incorrecta')
            ->set('new_password', 'nueva-12345')
            ->set('new_password_confirmation', 'nueva-12345')
            ->call('savePassword')
            ->assertHasErrors('current_password');

        Livewire::test('pages::ajustes')
            ->set('current_password', 'actual-123')
            ->set('new_password', 'nueva-12345')
            ->set('new_password_confirmation', 'nueva-12345')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('nueva-12345', $user->fresh()->password));
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->actingAs(User::factory()->create(['password' => 'actual-123']));

        Livewire::test('pages::ajustes')
            ->set('current_password', 'actual-123')
            ->set('new_password', 'nueva-12345')
            ->set('new_password_confirmation', 'otra-cosa-99')
            ->call('savePassword')
            ->assertHasErrors('new_password');
    }

    public function test_password_change_logs_out_other_devices(): void
    {
        Event::fake([OtherDeviceLogout::class]);
        $this->actingAs(User::factory()->create(['password' => 'actual-123']));

        Livewire::test('pages::ajustes')
            ->set('current_password', 'actual-123')
            ->set('new_password', 'nueva-12345')
            ->set('new_password_confirmation', 'nueva-12345')
            ->call('savePassword')
            ->assertHasNoErrors();

        Event::assertDispatched(OtherDeviceLogout::class);
    }

    public function test_logo_larger_than_4000_pixels_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::ajustes')
            ->set('logo', UploadedFile::fake()->image('logo.png', 4100, 100))
            ->call('saveLogo')
            ->assertHasErrors(['logo' => 'La imagen es demasiado grande. Usa una de máximo 4000 por 4000 píxeles.']);

        Setting::flush();
        $this->assertFalse(Branding::hasCustomLogo());
    }
}
