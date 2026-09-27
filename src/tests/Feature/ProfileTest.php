<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Test regresi untuk temuan #1 (§5 analisis handoff): fitur hapus-akun
     * mandiri bawaan Breeze SENGAJA dicabut karena berisiko cascade-delete
     * riwayat transaksi/stok (lihat catatan di ProfileController). Test ini
     * memastikan itu tetap tercabut kalau suatu saat ada yang tidak sengaja
     * menambahkannya kembali (mis. lewat merge branch lama / scaffold ulang).
     */
    public function test_self_service_account_deletion_route_no_longer_exists(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', ['password' => 'password']);

        $response->assertStatus(404);
        $this->assertNotNull($user->fresh(), 'User tidak boleh terhapus — route ini seharusnya sudah tidak ada.');
    }

    public function test_delete_account_section_is_not_rendered_on_profile_page(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk()->assertDontSee('Delete Account');
    }

    // NB: test_user_can_delete_their_account & test_correct_password_must_be_provided_to_delete_account
    // sengaja DIHAPUS — fitur hapus-akun mandiri (bawaan Breeze) sudah dicabut
    // dari sistem ini karena berisiko cascade-delete riwayat transaksi/stok
    // milik user (lihat catatan di ProfileController). Siklus hidup user
    // sekarang cuma lewat Nonaktifkan di Manajemen User (Admin) — belum ada
    // test otomatis untuk alur itu, jadi masih perlu ditest manual untuk saat ini.
}