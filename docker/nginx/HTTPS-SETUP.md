# Setup HTTPS (wajib untuk fitur scan barcode via kamera)

## Kenapa ini perlu

Browser modern memblokir `getUserMedia()` (dipakai fitur scan kamera di POS)
di luar *secure context*. `https://` selalu dianggap secure; `http://localhost`
atau `http://127.0.0.1` juga dianggap secure — **tapi `http://<IP LAN toko>`
(mis. `http://192.168.1.10:8080`) TIDAK dianggap secure**. Karena kasir
biasanya akses dari device terpisah (tablet/HP) lewat IP LAN server toko,
HTTPS wajib ada supaya kamera bisa dipakai dari device itu.

## Langkah 1 — Generate sertifikat (sekali per instalasi toko)

```bash
# Ganti dengan IP LAN server toko yang sebenarnya (cek dengan `ip addr` /
# `ipconfig`). Boleh isi lebih dari satu kalau perlu (mis. IP + hostname).
./docker/nginx/generate-certs.sh 192.168.1.10
```

Script ini otomatis pakai **mkcert** kalau ter-install di komputer server
toko (paling direkomendasikan — sertifikat langsung dipercaya browser, tanpa
warning), atau fallback ke **openssl self-signed** kalau mkcert tidak ada.

Ulangi langkah ini (lalu restart `webserver`) kalau IP LAN server berubah.

## Langkah 2 — Siapkan `.env` di root project

```bash
cp .env.docker.example .env
```

Untuk toko sungguhan (biar kasir tidak perlu ingat nomor port), edit `.env`:

```
APP_PORT=80
APP_SSL_PORT=443
```

(Kalau port 80/443 di server toko sudah dipakai layanan lain, biarkan
default `8080`/`8443` saja — kasir tinggal akses `https://192.168.1.10:8443`.)

## Langkah 3 — Jalankan stack

```bash
docker compose up -d --build
```

Coba akses `https://<IP-LAN-toko>` (atau `:8443` kalau pakai port default)
dari komputer server dulu. Kalau pakai openssl self-signed (bukan mkcert),
browser akan menampilkan peringatan "Not Secure" / "Your connection is not
private" — ini **normal**, klik "Advanced" → "Proceed anyway" (istilah persis
beda-beda tiap browser). Setelah dilewati sekali, origin tetap dianggap HTTPS
dan fitur kamera tetap berfungsi normal.

## Langkah 4 — Mempercayai sertifikat di device kasir lain (opsional, hilangkan warning)

Kalau pakai **mkcert** di Langkah 1, root CA-nya ada di folder yang
ditampilkan perintah `mkcert -CAROOT` di komputer server. Supaya device
kasir lain (tablet/HP terpisah) tidak melihat warning "Not Secure" sama
sekali, salin file `rootCA.pem` dari folder itu ke tiap device kasir dan
install sebagai "trusted certificate" (Android: Settings → Security → Install
from storage; iOS: AirDrop/email file lalu Settings → General → VPN & Device
Management → install profile, lalu aktifkan juga di Settings → General →
About → Certificate Trust Settings).

Kalau pakai **openssl self-signed** (tanpa mkcert), tiap device cukup buka
sekali dan klik "Proceed anyway" saat warning muncul — tidak perlu install
apa pun, tapi warning itu akan muncul lagi di device yang belum pernah
konfirmasi sebelumnya.

## Kalau nanti pindah ke domain publik + internet (bukan LAN toko lagi)

Setup di atas untuk jaringan lokal (LAN) tanpa domain publik. Kalau ke depan
sistem ini diakses lewat domain publik asli, ganti sertifikat dengan
**Let's Encrypt** (gratis, dipercaya semua browser tanpa setup tambahan di
device manapun) — beri tahu saya kalau sudah sampai tahap itu, konfigurasinya
sedikit berbeda (butuh certbot + domain yang sudah di-pointing ke server).