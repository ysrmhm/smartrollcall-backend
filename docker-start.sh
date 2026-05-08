#!/bin/sh
# SmartRollCall Render entry point
# Server'ı hemen başlat ki Render port scan'i geçsin,
# migrate ve seed arka planda paralel çalışsın.

set -e

cd /app

# 1) Önce migrate (hızlı, ~5-10 sn)
echo "==> Running migrations..."
php artisan migrate --force || echo "WARN: migrate failed, devam ediyoruz"

# 2) Seeder kontrolü — sadece users tablosu boşsa seed yap
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 | tr -d '[:space:]')

# Mazeret dosyalari icin sentinel: bu dosya varsa demo PDF'leri uretilmis demek.
SENTINEL="storage/app/mazeret/.demo-seeded-v3"

if [ "$USER_COUNT" = "0" ] || [ -z "$USER_COUNT" ]; then
  echo "==> Database is empty, full seed (FOREGROUND - tum loglari gor)..."
  # FOREGROUND seed: hata varsa goruruz. Render port scan 8dk timeout, seed ~2dk = guvenli.
  php artisan db:seed --class=DemoSeeder --force 2>&1 | sed 's/^/[SEED] /' || echo "[SEED] FAILED but continuing"
  mkdir -p storage/app/mazeret && touch "$SENTINEL"
  echo "[SEED] sentinel written: $SENTINEL"
elif [ ! -f "$SENTINEL" ]; then
  # DB dolu ama mazeret PDF'leri eksik — FOREGROUND reseed
  echo "==> Mazeret PDF eksik, reseed (FOREGROUND - tum loglari gor)..."
  php artisan db:seed --class=DemoSeeder --force 2>&1 | sed 's/^/[RESEED] /' || echo "[RESEED] FAILED but continuing"
  mkdir -p storage/app/mazeret && touch "$SENTINEL"
  echo "[RESEED] sentinel written: $SENTINEL"
else
  echo "==> Database has $USER_COUNT users + mazeret files, skipping seed."
fi

# 3) PHP server'ı hemen başlat — Render port scan'i hemen algılar
echo "==> Starting PHP server on 0.0.0.0:${PORT}"
exec php -S 0.0.0.0:${PORT} -t public public/index.php
