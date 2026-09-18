#!/usr/bin/env bash
#
# Generate sertifikat TLS untuk akses HTTPS lokal (localhost + jaringan LAN toko).
#
# KENAPA INI PERLU: fitur scan barcode via kamera (html5-qrcode -> getUserMedia)
# diblokir browser di luar "secure context". HTTPS adalah salah satu secure
# context; localhost/127.0.0.1 lewat HTTP saja juga dianggap secure, tapi IP
# LAN toko (mis. 192.168.1.10) lewat HTTP TIDAK dianggap secure oleh browser.
# Makanya kasir yang akses dari device terpisah (tablet/HP lain di WiFi toko)
# butuh HTTPS supaya kamera bisa dipakai.
#
# CARA PAKAI:
#   ./docker/nginx/generate-certs.sh                     # cuma localhost/127.0.0.1
#   ./docker/nginx/generate-certs.sh 192.168.1.10         # + IP LAN server toko
#   ./docker/nginx/generate-certs.sh 192.168.1.10 kasir-toko.local
#
# Jalankan ULANG script ini (lalu `docker compose restart webserver`) kalau IP
# LAN server toko berubah (mis. pindah router / ganti DHCP jadi static IP lain).
#
# Prioritas metode (otomatis dipilih, dari yang paling nyaman ke paling portable):
#   1. mkcert (kalau ter-install di host)   -> sertifikat OTOMATIS dipercaya
#      browser, TIDAK ada warning "Not Secure", asal root CA-nya juga di-install
#      di tiap device kasir (lihat HTTPS-SETUP.md, bagian "Mempercayai sertifikat").
#   2. openssl di host (kalau ter-install)  -> sertifikat self-signed, browser
#      akan tetap kasih warning "Not Secure" sampai user klik "Lanjutkan" atau
#      sertifikatnya di-trust manual — TAPI kamera tetap berfungsi normal
#      setelah warning itu di-lewati sekali, karena originnya tetap HTTPS.
#   3. openssl via container sekali-pakai  -> dipakai kalau host tidak punya
#      mkcert maupun openssl sama sekali (Docker sudah pasti ada, jadi ini
#      fallback yang selalu bisa jalan di mana pun).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERT_DIR="${SCRIPT_DIR}/certs"
mkdir -p "${CERT_DIR}"

# Kumpulan SAN (Subject Alternative Name) — wajib ada di sertifikat modern,
# browser menolak sertifikat yang cuma punya CN tanpa SAN yang cocok.
SANS=("localhost" "127.0.0.1" "::1")
for extra in "$@"; do
    SANS+=("${extra}")
done

echo "==> Sertifikat akan berlaku untuk: ${SANS[*]}"

# --- Susun daftar SAN dalam format yang dipahami mkcert & openssl ---
MKCERT_ARGS=()
OPENSSL_SAN_ENTRIES=()
san_index=1
for host in "${SANS[@]}"; do
    MKCERT_ARGS+=("${host}")
    if [[ "${host}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ || "${host}" == "::1" ]]; then
        OPENSSL_SAN_ENTRIES+=("IP.${san_index}:${host}")
    else
        OPENSSL_SAN_ENTRIES+=("DNS.${san_index}:${host}")
    fi
    san_index=$((san_index + 1))
done
OPENSSL_SAN_STRING=$(IFS=,; echo "${OPENSSL_SAN_ENTRIES[*]}")

# --- Metode 1: mkcert (paling direkomendasikan) ---
if command -v mkcert >/dev/null 2>&1; then
    echo "==> Pakai mkcert (sertifikat akan otomatis dipercaya browser di device ini)"
    mkcert -install
    mkcert \
        -cert-file "${CERT_DIR}/cert.pem" \
        -key-file "${CERT_DIR}/key.pem" \
        "${MKCERT_ARGS[@]}"
    echo "==> Selesai. Root CA mkcert ada di: $(mkcert -CAROOT)"
    echo "    Salin rootCA.pem dari path itu ke device kasir lain (lihat HTTPS-SETUP.md)."
    exit 0
fi

# --- Metode 2: openssl di host ---
if command -v openssl >/dev/null 2>&1; then
    echo "==> mkcert tidak ditemukan, pakai openssl (self-signed, browser akan warning sekali)"
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
        -keyout "${CERT_DIR}/key.pem" \
        -out "${CERT_DIR}/cert.pem" \
        -subj "/CN=toko-retail-management" \
        -addext "subjectAltName=${OPENSSL_SAN_STRING}"
    echo "==> Selesai."
    exit 0
fi

# --- Metode 3: openssl via container sekali-pakai (fallback terakhir) ---
echo "==> mkcert & openssl tidak ada di host, pakai container openssl sementara"
docker run --rm -v "${CERT_DIR}:/certs" alpine/openssl \
    req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout /certs/key.pem \
    -out /certs/cert.pem \
    -subj "/CN=toko-retail-management" \
    -addext "subjectAltName=${OPENSSL_SAN_STRING}"
echo "==> Selesai."