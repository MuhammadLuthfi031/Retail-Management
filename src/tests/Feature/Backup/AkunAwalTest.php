<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\CreateAdminUser;
use App\Models\User;
use Database\Seeders\RoleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** QA-011: akun awal tanpa password tetap di produksi — seeder demo dan perintah admin:buat. */
class AkunAwalTest extends TestCase
{
    use RefreshDatabase;

    // ---- seeder ----

    public function test_seeder_membuat_tiga_akun_demo_di_lingkungan_testing(): void
    {
        $this->seed(RoleUserSeeder::class);

        $this->assertSame(['admin', 'gudang', 'kasir'], User::orderBy('role')->pluck('role')->all());
        $this->assertTrue(Hash::check('password', User::where('email', 'admin@toko.test')->value('password')));
    }

    #[DataProvider('lingkunganBukanDev')]
    public function test_seeder_tidak_membuat_akun_di_luar_local_dan_testing(string $env): void
    {
        $this->app->detectEnvironment(fn () => $env);

        // --force: di "production" db:seed bawaan Laravel meminta konfirmasi lebih dulu.
        $this->artisan('db:seed', ['--class' => RoleUserSeeder::class, '--force' => true])
            ->expectsOutputToContain('php artisan admin:buat')
            ->assertExitCode(0);

        $this->assertSame(0, User::count(), "env {$env}: tidak boleh ada akun berpassword tetap");
    }

    public static function lingkunganBukanDev(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'nama tak dikenal' => ['toko-cabang-2'],
        ];
    }

    public function test_seeder_demo_aman_dijalankan_dua_kali(): void
    {
        $this->seed(RoleUserSeeder::class);
        $this->seed(RoleUserSeeder::class);

        $this->assertSame(3, User::count());
    }

    // ---- admin:buat ----

    private function jalankan(string $password, string $confirm, array $opts = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('admin:buat', $opts + ['--name' => 'Pak Budi', '--email' => 'Budi@Toko.Id'])
            ->expectsQuestion(CreateAdminUser::Q_PASSWORD, $password)
            ->expectsQuestion(CreateAdminUser::Q_CONFIRM, $confirm);
    }

    public function test_membuat_admin_dengan_password_ter_hash_dan_email_huruf_kecil(): void
    {
        $this->jalankan('kata-sandi-panjang-123', 'kata-sandi-panjang-123')
            ->expectsOutputToContain('Akun Admin dibuat: budi@toko.id')
            ->assertExitCode(0);

        $user = User::where('email', 'budi@toko.id')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('kata-sandi-panjang-123', $user->password);
        $this->assertTrue(Hash::check('kata-sandi-panjang-123', $user->password), 'tidak ter-hash ganda');
    }

    public function test_password_tidak_pernah_dicetak(): void
    {
        $this->jalankan('kata-sandi-panjang-123', 'kata-sandi-panjang-123')
            ->doesntExpectOutputToContain('kata-sandi-panjang-123')
            ->assertExitCode(0);
    }

    public function test_password_pendek_ditolak_dan_tidak_ada_akun_dibuat(): void
    {
        $this->jalankan('pendek123', 'pendek123')
            ->expectsOutputToContain('Tidak ada akun yang dibuat')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_konfirmasi_password_harus_cocok(): void
    {
        $this->jalankan('kata-sandi-panjang-123', 'kata-sandi-panjang-124')->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_email_ganda_dan_email_tidak_valid_ditolak(): void
    {
        User::factory()->create(['email' => 'budi@toko.id']);

        $this->jalankan('kata-sandi-panjang-123', 'kata-sandi-panjang-123')->assertExitCode(1);
        $this->artisan('admin:buat', ['--name' => 'X', '--email' => 'bukan-email'])
            ->expectsQuestion(CreateAdminUser::Q_PASSWORD, 'kata-sandi-panjang-123')
            ->expectsQuestion(CreateAdminUser::Q_CONFIRM, 'kata-sandi-panjang-123')
            ->assertExitCode(1);

        $this->assertSame(1, User::count());
    }

    public function test_nama_dan_email_ditanya_bila_opsi_tidak_diberikan(): void
    {
        $this->artisan('admin:buat')
            ->expectsQuestion('Nama lengkap', 'Bu Sari')
            ->expectsQuestion('Email (untuk login)', 'sari@toko.id')
            ->expectsQuestion(CreateAdminUser::Q_PASSWORD, 'kata-sandi-panjang-123')
            ->expectsQuestion(CreateAdminUser::Q_CONFIRM, 'kata-sandi-panjang-123')
            ->assertExitCode(0);

        $this->assertSame('Bu Sari', User::where('email', 'sari@toko.id')->value('name'));
    }
}