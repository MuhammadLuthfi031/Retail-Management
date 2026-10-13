<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun DEMO untuk pengembangan/test — password "password" diketahui siapa pun yang
 * membaca repo ini (QA-011). Karena itu HANYA berjalan di lingkungan local/testing.
 * Di lingkungan lain (production, staging, nama env tak dikenal) seeder ini tidak
 * membuat akun apa pun; buat Admin pertama dengan: php artisan admin:buat
 */
class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn(
                'Akun demo dilewati: lingkungan "'.app()->environment().'" bukan local/testing. '
                .'Buat Admin pertama dengan: php artisan admin:buat'
            );

            return;
        }

        // firstOrCreate: aman dijalankan ulang tanpa gagal karena email ganda.
        foreach ([
            ['Administrator', 'admin@toko.test', 'admin'],
            ['Kasir Toko', 'kasir@toko.test', 'kasir'],
            ['Staff Gudang', 'gudang@toko.test', 'gudang'],
        ] as [$name, $email, $role]) {
            User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'role' => $role]
            );
        }
    }
}