#!/bin/sh
# SmartRollCall Render entry point
# Server'ı hemen başlat ki Render port scan'i geçsin,
# migrate ve seed arka planda paralel çalışsın.

set -e

cd /app

# 1) Önce migrate (hızlı, ~5-10 sn)
echo "==> Running migrations..."
php artisan migrate --force || echo "WARN: migrate failed, devam ediyoruz"

# 2) Seeder kontrolü.
#    Normalde: SADECE users tablosu boşsa seed yapılır (mevcut veri korunur).
#    FORCE_RESEED=true ise: veritabanı tamamen temizlenip yeniden seed edilir.
#      (Numara/şifre formatı değişikliği gibi tek seferlik düzeltmeler için.
#       Çalıştıktan sonra Render Environment'tan FORCE_RESEED'i kaldır/false yap,
#       yoksa her deploy'da veri sıfırlanır.)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 | tr -d '[:space:]')

if [ "$FORCE_RESEED" = "true" ]; then
  echo "==> FORCE_RESEED=true → veritabanı sıfırlanıp yeniden seed edilecek (BACKGROUND)..."
  (
    php artisan migrate:fresh --force 2>&1 | sed 's/^/[SEED] /';
    php artisan db:seed --class=DemoSeeder --force 2>&1 \
      | sed 's/^/[SEED] /' \
      || echo "[SEED] FAILED";
    echo "[SEED] DONE"
  ) &
elif [ "$USER_COUNT" = "0" ] || [ -z "$USER_COUNT" ]; then
  echo "==> Database is empty, full seed in BACKGROUND (loglar [SEED] prefix'i ile)..."
  (
    php artisan db:seed --class=DemoSeeder --force 2>&1 \
      | sed 's/^/[SEED] /' \
      || echo "[SEED] FAILED";
    echo "[SEED] DONE"
  ) &
else
  echo "==> Database has $USER_COUNT users, skipping seed (mevcut veri korunuyor)."
fi

# 3) PHP server'ı hemen başlat — Render port scan'i hemen algılar
echo "==> Starting PHP server on 0.0.0.0:${PORT}"
exec php -S 0.0.0.0:${PORT} -t public public/index.php
