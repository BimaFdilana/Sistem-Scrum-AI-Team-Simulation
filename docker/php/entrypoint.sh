#!/bin/sh
# Entrypoint container Laravel: siapkan APP_KEY, database, migrasi, lalu jalan.
set -e
cd /app

# 1. APP_KEY — generate sekali jalan kalau belum di-set (set permanen di .env)
if [ -z "${APP_KEY:-}" ]; then
  export APP_KEY
  APP_KEY="$(php artisan key:generate --show --no-interaction)"
  echo ">>> APP_KEY dibuat otomatis untuk sesi ini."
  echo ">>> Simpan ke .env agar permanen: APP_KEY=$APP_KEY"
fi

# 2. SQLite — pastikan file database ada (volume app-database)
mkdir -p database
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
  echo ">>> database/database.sqlite dibuat."
fi

# 3. Migrasi (idempotent)
php artisan migrate --force --no-interaction

# 4. Seed admin pertama (idempotent: no-op bila admin sudah ada).
#    WAJIB jalan di sini — tanpa ini, deploy segar tidak punya user admin
#    dan Bima tidak bisa login. Gagalnya seed tidak boleh mematikan container.
if php artisan db:seed --force --no-interaction; then
  echo ">>> admin workspace siap."
else
  echo ">>> PERINGATAN: db:seed gagal — login admin mungkin belum tersedia."
  echo ">>> Cek manual: docker compose exec app php artisan db:seed"
fi

# 5. Cache konfigurasi untuk performa (setelah seed, agar config ter-cache)
php artisan optimize --no-interaction >/dev/null 2>&1 || true

exec "$@"
