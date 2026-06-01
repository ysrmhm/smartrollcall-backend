#!/bin/sh
# SmartRollCall Render entry point
# Server'ı hemen başlat ki Render port scan'i geçsin,
# migrate ve seed arka planda paralel çalışsın.

set -e

cd /app

# 1) Önce migrate (hızlı, ~5-10 sn)
echo "==> Running migrations..."
php artisan migrate --force || echo "WARN: migrate failed, devam ediyoruz"

# 2) Seeder kontrolü — SADECE users tablosu tamamen boşsa seed yap.
#    (Önceki sürümdeki sentinel/reseed mantığı kaldırıldı: her deploy'da
#     mevcut veriyi eziyordu. Artık veri varsa asla dokunulmaz — mega demo
#     verisi kalıcıdır. Sıfırdan kurulumda yine DemoSeeder ile dolar.)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 | tr -d '[:space:]')

if [ "$USER_COUNT" = "0" ] || [ -z "$USER_COUNT" ]; then
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
