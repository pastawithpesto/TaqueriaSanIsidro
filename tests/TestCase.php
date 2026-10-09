<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La configuración se guarda en caché estática; cada prueba empieza limpia.
        Setting::flush();
    }
}
