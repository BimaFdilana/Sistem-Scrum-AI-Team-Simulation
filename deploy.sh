#!/usr/bin/env bash
# ===========================================================================
# ScrumSpace — deploy satu perintah ke VPS.
#   ./deploy.sh
# Aman dijalankan ulang untuk update (git pull manual sebelum update).
# ===========================================================================
set -euo pipefail
cd "$(dirname "$0")"

say() { echo "==> $1"; }
warn() { echo "    !! $1"; }

say "1/5  Cek Docker..."
command -v docker >/dev/null || { warn "Docker belum terinstall. Jalankan dulu:"; echo "     curl -fsSL https://get.docker.com | sh"; exit 1; }
docker compose version >/dev/null 2>&1 || { warn "Docker Compose plugin tidak ditemukan."; exit 1; }
command -v openssl >/dev/null || { warn "openssl tidak ditemukan."; exit 1; }

say "2/5  Siapkan .env..."
[ -f .env ] || { cp .env.docker.example .env; echo "     .env dibuat dari contoh."; }

set_var() { # $1=NAME $2=value
    if grep -q "^$1=" .env; then
        sed -i "s|^$1=.*|$1=$2|" .env
    else
        echo "$1=$2" >> .env
    fi
}
gen_hex() { # $1=NAME — isi bila kosong
    grep -q "^$1=.\+" .env 2>/dev/null || { set_var "$1" "$(openssl rand -hex 16)"; echo "     $1 dibuat otomatis."; }
}
if ! grep -q "^APP_KEY=.\+" .env 2>/dev/null; then
    set_var "APP_KEY" "base64:$(openssl rand -base64 32)"
    echo "     APP_KEY dibuat otomatis."
fi
gen_hex CLIPROXY_MANAGEMENT_KEY
gen_hex CLIPROXY_API_KEY

say "3/5  Cek variabel wajib..."
missing=0
for v in WORKSPACE_ADMIN_EMAIL WORKSPACE_ADMIN_PASSWORD; do
    grep -q "^$v=.\+" .env 2>/dev/null || { warn "$v belum diisi di .env"; missing=1; }
done
if [ "$missing" -eq 1 ]; then
    echo "     Isi dulu nilai di atas pada file .env, lalu jalankan ./deploy.sh lagi."
    exit 1
fi

say "4/5  Build & jalankan (bisa beberapa menit saat pertama kali)..."
docker compose up -d --build

say "5/5  Status..."
sleep 6
docker compose ps
echo ""
echo "-----------------------------------------------------------------"
echo " Selesai."
echo " Buka:  http://<ip-vps>  atau  https://<domain> (bila APP_DOMAIN diisi)"
echo " Login: email & password admin dari .env"
echo " Log:   docker compose logs -f app"
echo " Update berikutnya:  git pull && ./deploy.sh"
echo "-----------------------------------------------------------------"
