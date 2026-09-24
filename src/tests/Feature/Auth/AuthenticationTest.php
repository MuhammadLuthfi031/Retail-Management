<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    /**
     * Versi bawaan Breeze mengasumsikan SEMUA user diarahkan ke 'dashboard'.
     * Di sistem ini tujuan setelah login ditentukan role (User::homeRouteName()).
     */
    public static function homeRoutes(): array
    {
        return [
            'admin' => ['admin', 'admin.dashboard'],
            'kasir' => ['kasir', 'kasir.pos'],
            'gudang' => ['gudang', 'gudang.kategori.index'],
        ];
    }

    #[DataProvider('homeRoutes')]
    public function test_users_are_redirected_to_their_role_home_after_login(string $role, string $expectedRoute): void
    {
        $user = User::factory()->{$role}()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route($expectedRoute, absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_log_in_even_with_the_correct_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('account_status');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        // Percobaan ke-6 dengan password BENAR tetap harus ditolak (brute-force guard).
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
