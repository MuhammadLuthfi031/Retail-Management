<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Membuat akun Admin pertama (atau tambahan) secara INTERAKTIF — pengganti seeder
 * berpassword tetap di produksi (QA-011). Password diketik tersembunyi, tidak masuk
 * riwayat shell, dan tidak pernah dicetak kembali.
 */
class CreateAdminUser extends Command
{
    public const Q_PASSWORD = 'Password (minimal 12 karakter; tidak tampil saat diketik)';

    public const Q_CONFIRM = 'Ulangi password';

    protected $signature = 'admin:buat
        {--name= : Nama lengkap (bila kosong akan ditanya)}
        {--email= : Email untuk login (bila kosong akan ditanya)}';

    protected $description = 'Buat akun Admin baru secara interaktif (password tidak disimpan di riwayat perintah)';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Nama lengkap')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email (untuk login)'))));
        $password = (string) $this->secret(self::Q_PASSWORD);
        $confirmation = (string) $this->secret(self::Q_CONFIRM);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'confirmed', Password::min(12)],
            ],
            [],
            ['name' => 'nama', 'email' => 'email', 'password' => 'password']
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            $this->line('Tidak ada akun yang dibuat.');

            return self::FAILURE;
        }

        // Cast 'hashed' pada model User yang meng-hash password; jangan Hash::make di sini (hash ganda).
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->info("Akun Admin dibuat: {$user->email}");
        $this->line('Simpan password di tempat yang aman. Anda bisa login sekarang.');

        return self::SUCCESS;
    }
}