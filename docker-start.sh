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

if [ "$USER_COUNT" = "0" ] || [ -z "$USER_COUNT" ]; then
  echo "==> Database is empty, seeding in background..."
  # Arka planda seed et — port binding'i bloke etmesin
  (php artisan db:seed --class=DemoSeeder --force > /tmp/seed.log 2>&1 &)
else
  echo "==> Database has $USER_COUNT users, skipping seed."
fi

# 3) PHP server'ı hemen başlat — Render port scan'i hemen algılar
echo "==> Starting PHP server on 0.0.0.0:${PORT}"
exec php -S 0.0.0.0:${PORT} -t public public/index.php
