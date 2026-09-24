<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Layout memanggil @vite(...). Tanpa ini, setiap test yang merender
        // halaman akan error "Vite manifest not found" kecuali `npm run build`
        // sudah dijalankan dulu — tidak relevan untuk menguji logika backend.
        $this->withoutVite();
    }
}
