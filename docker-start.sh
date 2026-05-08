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
  echo "==> Database is empty, full seed in BACKGROUND (loglar [SEED] prefix'i ile)..."
  # Background ama stdout/stderr'i parent'a (Render log) yonlendir.
  # nohup + & ile fork edilir; cikti `tee /proc/.../fd/1` ile Render'a dusurulur.
  (
    php artisan db:seed --class=DemoSeeder --force 2>&1 \
      | sed 's/^/[SEED] /' \
      || echo "[SEED] FAILED";
    mkdir -p storage/app/mazeret && touch "$SENTINEL";
    echo "[SEED] DONE - sentinel: $SENTINEL"
  ) &
elif [ ! -f "$SENTINEL" ]; then
  echo "==> Mazeret PDF eksik, reseed in BACKGROUND (loglar [RESEED] prefix'i ile)..."
  (
    php artisan db:seed --class=DemoSeeder --force 2>&1 \
      | sed 's/^/[RESEED] /' \
      || echo "[RESEED] FAILED";
    mkdir -p storage/app/mazeret && touch "$SENTINEL";
    echo "[RESEED] DONE - sentinel: $SENTINEL"
  ) &
else
  echo "==> Database has $USER_COUNT users + mazeret files, skipping seed."
fi

# 3) PHP server'ı hemen başlat — Render port scan'i hemen algılar
echo "==> Starting PHP server on 0.0.0.0:${PORT}"
exec php -S 0.0.0.0:${PORT} -t public public/index.php
