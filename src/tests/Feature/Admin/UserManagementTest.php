<?php

namespace Tests\Feature\Admin;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BuildsRetailData;
use Tests\TestCase;

/**
 * QA-004 (bagian UserController, §7.2 spesifikasi) — korektivitas manajemen
 * user. Fokus utama: proteksi "admin aktif terakhir" (satu-satunya pencegah
 * lockout total dari Modul Admin), nonaktifkan-bukan-hapus (jejak audit
 * transaksi tidak boleh hilang), dan password selalu tersimpan ter-hash.
 */
class UserManagementTest extends TestCase
{
    use BuildsRetailData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Utama']);
    }

    // === Helper ===

    private function tambah(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('admin.users.store'), array_merge([
            'name' => 'Karyawan Baru',
            'email' => 'baru@toko.test',
            'role' => 'kasir',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ], $override));
    }

    private function ubah(User $target, array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put(route('admin.users.update', $target), array_merge([
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role,
        ], $override));
    }

    private function toggle(User $target, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put(route('admin.users.toggle-status', $target));
    }

    private function resetPw(User $target, array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put(route('admin.users.reset-password', $target), array_merge([
            'password' => 'passwordbaru1',
            'password_confirmation' => 'passwordbaru1',
        ], $override));
    }

    // === store() ===

    public function test_tambah_user_tersimpan_aktif_dengan_password_ter_hash(): void
    {
        $this->tambah(['role' => 'gudang'])->assertRedirect()->assertSessionHas('success');

        $user = User::where('email', 'baru@toko.test')->sole();
        $this->assertSame('Karyawan Baru', $user->name);
        $this->assertSame('gudang', $user->role);
        $this->assertTrue($user->is_active);

        // Password TIDAK plaintext, dan Hash::check lolos (menangkap regresi
        // hash ganda: Hash::make di controller + cast 'hashed' di model).
        $this->assertNotSame('rahasia123', $user->password);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    public function test_user_baru_bisa_login_dengan_password_yang_diset_admin(): void
    {
        $this->tambah(['role' => 'kasir'])->assertSessionHas('success');
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'baru@toko.test', 'password' => 'rahasia123']);

        $this->assertAuthenticated();
        $this->assertSame('baru@toko.test', auth()->user()->email);
    }

    public function test_tambah_user_data_tidak_valid_ditolak_dan_tidak_ada_yang_dibuat(): void
    {
        $jumlahAwal = User::count();

        $kasus = [
            'email sudah dipakai' => [['email' => $this->admin->email], 'email'],
            'email bukan format email' => [['email' => 'bukan-email'], 'email'],
            'role di luar daftar' => [['role' => 'superadmin'], 'role'],
            'role kosong' => [['role' => ''], 'role'],
            'konfirmasi password beda' => [['password_confirmation' => 'beda'], 'password'],
            'password terlalu pendek' => [['password' => 'pendek', 'password_confirmation' => 'pendek'], 'password'],
            'nama kosong' => [['name' => ''], 'name'],
        ];

        foreach ($kasus as $nama => [$override, $field]) {
            try {
                $this->tambah($override)->assertSessionHasErrors($field);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->fail("Kasus '{$nama}' seharusnya ditolak dengan error pada '{$field}': " . $e->getMessage());
            }
        }

        $this->assertSame($jumlahAwal, User::count());
    }

    public function test_tambah_user_mengabaikan_is_active_dari_input(): void
    {
        // Mass-assignment: klien tidak boleh membuat akun yang langsung nonaktif/aktif sesuka hati lewat field ekstra.
        $this->tambah(['is_active' => 0])->assertSessionHas('success');

        $this->assertTrue(User::where('email', 'baru@toko.test')->sole()->is_active);
    }

    // === update() ===

    public function test_ubah_nama_email_dan_role_user_lain(): void
    {
        $kasir = User::factory()->kasir()->create();

        $this->ubah($kasir, ['name' => 'Nama Baru', 'email' => 'baru@toko.test', 'role' => 'gudang'])
            ->assertSessionHas('success');

        $kasir->refresh();
        $this->assertSame('Nama Baru', $kasir->name);
        $this->assertSame('baru@toko.test', $kasir->email);
        $this->assertSame('gudang', $kasir->role);
    }

    public function test_email_milik_sendiri_boleh_dikirim_ulang_tapi_milik_orang_lain_ditolak(): void
    {
        $kasir = User::factory()->kasir()->create(['email' => 'kasir@toko.test']);

        $this->ubah($kasir, ['name' => 'Ganti Nama'])->assertSessionHasNoErrors(); // email sama dgn miliknya sendiri

        $this->ubah($kasir, ['email' => $this->admin->email])->assertSessionHasErrors('email');
        $this->assertSame('kasir@toko.test', $kasir->fresh()->email);
    }

    public function test_ubah_role_ke_nilai_di_luar_daftar_ditolak(): void
    {
        $kasir = User::factory()->kasir()->create();

        $this->ubah($kasir, ['role' => 'owner'])->assertSessionHasErrors('role');

        $this->assertSame('kasir', $kasir->fresh()->role);
    }

    public function test_update_mengabaikan_password_dan_is_active_dari_input(): void
    {
        $kasir = User::factory()->kasir()->create();
        $hashAsli = $kasir->password;

        $this->ubah($kasir, ['name' => 'X', 'password' => 'diselundupkan1', 'is_active' => 0])
            ->assertSessionHas('success');

        $kasir->refresh();
        $this->assertSame($hashAsli, $kasir->password, 'ganti password hanya lewat reset-password');
        $this->assertTrue($kasir->is_active, 'ganti status hanya lewat toggle-status');
    }

    // --- Proteksi admin aktif terakhir ---

    public function test_admin_tunggal_tidak_bisa_menurunkan_role_dirinya_sendiri(): void
    {
        // Ada user aktif lain berrole BUKAN admin — tidak boleh dianggap "admin pengganti".
        User::factory()->kasir()->create();
        User::factory()->gudang()->create();

        $this->ubah($this->admin, ['role' => 'kasir'])->assertSessionHasErrors('role');

        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_admin_lain_yang_nonaktif_tidak_dihitung_sebagai_pengganti(): void
    {
        User::factory()->admin()->inactive()->create();

        $this->ubah($this->admin, ['role' => 'gudang'])->assertSessionHasErrors('role');

        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_admin_boleh_menurunkan_role_dirinya_kalau_ada_admin_aktif_lain(): void
    {
        User::factory()->admin()->create();

        $this->ubah($this->admin, ['role' => 'kasir'])->assertSessionHas('success');

        $this->assertSame('kasir', $this->admin->fresh()->role);
    }

    public function test_admin_tunggal_tetap_boleh_mengubah_nama_dan_email_dirinya_tanpa_ganti_role(): void
    {
        $this->ubah($this->admin, ['name' => 'Admin Rename', 'email' => 'rename@toko.test'])
            ->assertSessionHas('success');

        $this->assertSame('Admin Rename', $this->admin->fresh()->name);
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_admin_boleh_menurunkan_role_admin_lain_selama_dirinya_tetap_admin_aktif(): void
    {
        $adminLain = User::factory()->admin()->create();

        $this->ubah($adminLain, ['role' => 'kasir'])->assertSessionHas('success');

        $this->assertSame('kasir', $adminLain->fresh()->role);
        $this->assertSame('admin', $this->admin->fresh()->role); // masih ada admin aktif
    }

    // === toggleActive() ===

    public function test_nonaktifkan_user_lain_memutus_aksesnya_ke_rute_bisnis_dan_data_riwayat_tetap_ada(): void
    {
        $kasir = User::factory()->kasir()->create();
        $trx = $this->makeTransaction(['user_id' => $kasir->id], [[]]);

        $this->toggle($kasir)->assertSessionHas('success');

        $this->assertFalse($kasir->fresh()->is_active);

        // Akses langsung ditutup oleh middleware role.
        $this->actingAs($kasir->fresh())->get(route('kasir.pos'))->assertForbidden();

        // Nonaktifkan BUKAN hapus: akun & seluruh riwayatnya utuh (cascade FK tidak terpicu).
        $this->assertNotNull(User::find($kasir->id));
        $this->assertNotNull(Transaction::find($trx->id));
        $this->assertSame(1, $trx->details()->count());
    }

    public function test_aktifkan_kembali_memulihkan_akses(): void
    {
        $kasir = User::factory()->kasir()->inactive()->create();

        $this->actingAs($kasir)->get(route('kasir.pos'))->assertForbidden();

        $this->toggle($kasir)->assertSessionHas('success');

        $this->assertTrue($kasir->fresh()->is_active);
        $this->actingAs($kasir->fresh())->get(route('kasir.pos'))->assertOk();
    }

    public function test_admin_tidak_bisa_menonaktifkan_akunnya_sendiri(): void
    {
        // Sengaja ada admin aktif lain: yang diuji aturan "bukan akun sendiri", bukan aturan admin terakhir.
        User::factory()->admin()->create();

        $this->toggle($this->admin)->assertSessionHas('error');

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_admin_boleh_menonaktifkan_admin_lain(): void
    {
        $adminLain = User::factory()->admin()->create();

        $this->toggle($adminLain)->assertSessionHas('success');

        $this->assertFalse($adminLain->fresh()->is_active);
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    /**
     * CATATAN (QA): cabang `isLastActiveAdmin()` untuk target BUKAN diri
     * sendiri di toggleActive() tidak bisa dicapai lewat HTTP — pelaku harus
     * admin aktif (middleware role:admin), sehingga target tidak mungkin
     * admin aktif terakhir. Cabang itu benteng cadangan (defense-in-depth);
     * yang dijaga test di atas adalah jalur yang benar-benar bisa terjadi.
     */
    public function test_nonaktifkan_tidak_pernah_menghapus_baris_user(): void
    {
        $kasir = User::factory()->kasir()->create();
        $jumlah = User::count();

        $this->toggle($kasir);
        $this->toggle($kasir);

        $this->assertSame($jumlah, User::count());
    }

    // === resetPassword() ===

    public function test_reset_password_mengganti_password_dan_yang_lama_tidak_berlaku(): void
    {
        $kasir = User::factory()->kasir()->create(['password' => Hash::make('lama12345')]);

        $this->resetPw($kasir)->assertSessionHas('success');

        $kasir->refresh();
        $this->assertTrue(Hash::check('passwordbaru1', $kasir->password));
        $this->assertFalse(Hash::check('lama12345', $kasir->password));
        $this->assertNotSame('passwordbaru1', $kasir->password);
    }

    public function test_user_bisa_login_dengan_password_hasil_reset_dan_tidak_dengan_yang_lama(): void
    {
        $kasir = User::factory()->kasir()->create(['password' => Hash::make('lama12345')]);

        $this->resetPw($kasir)->assertSessionHas('success');
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => $kasir->email, 'password' => 'lama12345']);
        $this->assertGuest();

        $this->post(route('login'), ['email' => $kasir->email, 'password' => 'passwordbaru1']);
        $this->assertAuthenticatedAs($kasir);
    }

    public function test_reset_password_tidak_valid_ditolak_dan_password_lama_tetap(): void
    {
        $kasir = User::factory()->kasir()->create(['password' => Hash::make('lama12345')]);

        $this->resetPw($kasir, ['password_confirmation' => 'beda'])->assertSessionHasErrors('password');
        $this->resetPw($kasir, ['password' => 'pendek', 'password_confirmation' => 'pendek'])->assertSessionHasErrors('password');
        $this->resetPw($kasir, ['password' => '', 'password_confirmation' => ''])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('lama12345', $kasir->fresh()->password));
    }

    public function test_reset_password_tidak_mengubah_role_atau_status_aktif(): void
    {
        $gudang = User::factory()->gudang()->inactive()->create();

        $this->resetPw($gudang)->assertSessionHas('success');

        $this->assertSame('gudang', $gudang->fresh()->role);
        $this->assertFalse($gudang->fresh()->is_active);
    }

    // === index() ===

    public function test_daftar_user_terurut_nama_dan_dipaginasi_10_per_halaman(): void
    {
        // Sengaja dibuat TERBALIK (12 -> 1) supaya urutan id berlawanan dengan
        // urutan nama — kalau orderBy('name') hilang, hasilnya langsung beda.
        foreach (range(12, 1) as $i) {
            User::factory()->kasir()->create(['name' => sprintf('Karyawan %02d', $i)]);
        }
        // total 13 (12 + Admin Utama)

        $hal1 = $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->viewData('users');
        $hal2 = $this->actingAs($this->admin)->get(route('admin.users.index', ['page' => 2]))->viewData('users');

        $this->assertSame(13, $hal1->total());
        $this->assertCount(10, $hal1);
        $this->assertSame(
            ['Admin Utama', 'Karyawan 01', 'Karyawan 02', 'Karyawan 03', 'Karyawan 04', 'Karyawan 05', 'Karyawan 06', 'Karyawan 07', 'Karyawan 08', 'Karyawan 09'],
            $hal1->pluck('name')->all()
        );
        $this->assertSame(['Karyawan 10', 'Karyawan 11', 'Karyawan 12'], $hal2->pluck('name')->all());
    }

    public function test_filter_pencarian_role_dan_status(): void
    {
        User::factory()->kasir()->create(['name' => 'Budi Santoso', 'email' => 'budi@toko.test']);
        User::factory()->kasir()->inactive()->create(['name' => 'Citra Dewi', 'email' => 'citra@toko.test']);
        User::factory()->gudang()->create(['name' => 'Dedi Gudang', 'email' => 'dedi@toko.test']);

        $cari = fn (array $q) => $this->actingAs($this->admin)
            ->get(route('admin.users.index', $q))->assertOk()->viewData('users')->pluck('name')->all();

        $this->assertSame(['Budi Santoso'], $cari(['search' => 'santoso']));      // cocok nama
        $this->assertSame(['Citra Dewi'], $cari(['search' => 'citra@toko']));    // cocok email
        $this->assertSame(['Dedi Gudang'], $cari(['role' => 'gudang']));
        $this->assertEqualsCanonicalizing(['Budi Santoso', 'Citra Dewi'], $cari(['role' => 'kasir']));
        $this->assertSame(['Citra Dewi'], $cari(['status' => 'inactive']));
        $this->assertNotContains('Citra Dewi', $cari(['status' => 'active']));
        $this->assertSame(['Budi Santoso'], $cari(['role' => 'kasir', 'status' => 'active'])); // AND, bukan OR
    }

    // === Akses ===

    public function test_kasir_dan_gudang_ditolak_di_semua_endpoint_manajemen_user_dan_tidak_ada_yang_berubah(): void
    {
        $target = User::factory()->kasir()->create(['name' => 'Target']);
        $hashAsli = $target->password;
        $jumlah = User::count();

        foreach (['kasir', 'gudang'] as $role) {
            $pelaku = User::factory()->{$role}()->create();
            $jumlah++;

            $this->actingAs($pelaku)->get(route('admin.users.index'))->assertForbidden();
            $this->tambah(as: $pelaku)->assertForbidden();
            $this->ubah($target, ['role' => 'admin'], $pelaku)->assertForbidden();
            $this->toggle($target, $pelaku)->assertForbidden();
            $this->resetPw($target, [], $pelaku)->assertForbidden();
        }

        $target->refresh();
        $this->assertSame('kasir', $target->role);
        $this->assertTrue($target->is_active);
        $this->assertSame($hashAsli, $target->password);
        $this->assertSame($jumlah, User::count());
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $target = User::factory()->kasir()->create();

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->post(route('admin.users.store'), [])->assertRedirect(route('login'));
        $this->put(route('admin.users.update', $target), [])->assertRedirect(route('login'));
        $this->put(route('admin.users.toggle-status', $target))->assertRedirect(route('login'));
        $this->put(route('admin.users.reset-password', $target), [])->assertRedirect(route('login'));
    }
}