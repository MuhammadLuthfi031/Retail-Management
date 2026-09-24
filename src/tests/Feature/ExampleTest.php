<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root URL ('/') tidak punya halaman publik: selalu redirect. Versi bawaan
 * Laravel (assertStatus(200)) otomatis gagal untuk aplikasi internal seperti ini.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_root_redirects_authenticated_users_to_their_role_home(): void
    {
        $expected = [
            'admin' => 'admin.dashboard',
            'kasir' => 'kasir.pos',
            'gudang' => 'gudang.kategori.index',
        ];

        foreach ($expected as $role => $route) {
            $user = User::factory()->{$role}()->create();

            $this->actingAs($user)->get('/')->assertRedirect(route($route));
        }
    }
}
