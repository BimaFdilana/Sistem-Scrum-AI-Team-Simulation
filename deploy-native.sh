#!/usr/bin/env bash
# ===========================================================================
# ScrumSpace — deploy NATIVE tanpa Docker (untuk VPS yang tidak bisa pakai Docker).
#   ./deploy-native.sh              Deploy penuh (cek -> install -> setup -> jalan)
#   ./deploy-native.sh --preflight  Hanya pemeriksaan, tanpa mengubah apa pun.
#
# Yang diinstall: PHP 8.5 (+FPM), Composer, Node 24, nginx, CLIProxyAPI binary.
# Yang dijalankan: nginx (web), php-fpm, cliproxy (systemd), queue worker (systemd).
# Semua pesan error dalam Bahasa Indonesia + cara memperbaikinya.
# ===========================================================================
set -euo pipefail
cd "$(dirname "$0")"

PREFLIGHT_ONLY=0
[ "${1:-}" = "--preflight" ] && PREFLIGHT_ONLY=1

APP_DIR="/opt/scrumspace"
PHP_VER="8.5"
CLIPROXY_VER="${CLIPROXY_VERSION:-8.0.23}"

say()  { echo "==> $1"; }
ok()   { echo "    [OK] $1"; }
warn() { echo "    [!!] $1"; }

fail() {
    echo ""
    echo "-----------------------------------------------------------------"
    echo " GAGAL: $1"
    [ -n "${2:-}" ] && echo " PERBAIKI: $2"
    echo "-----------------------------------------------------------------"
    exit 1
}

need_root() {
    [ "$(id -u)" -eq 0 ] || fail "Script ini harus dijalankan sebagai root (sudo)." \
        "Jalankan: sudo ./deploy-native.sh"
}

# ---------------------------------------------------------------------------
# PRE-FLIGHT
# ---------------------------------------------------------------------------
preflight() {
    say "Pre-flight check (native)..."

    # 1. OS Ubuntu 22.04/24.04?
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        case "${VERSION_ID:-}" in
            22.04|24.04) ok "Ubuntu $VERSION_ID" ;;
            *) fail "OS tidak didukung: ${PRETTY_NAME:-?}." \
                    "Script ini untuk Ubuntu 22.04/24.04." ;;
        esac
    fi

    # 2. Internet?
    curl -sI --max-time 10 https://github.com >/dev/null 2>&1 \
        || fail "Tidak ada koneksi internet." \
                "Periksa koneksi VPS."
    ok "internet tersedia"

    # 3. Disk >= 3GB?
    disk_kb=$(df -k --output=avail / | tail -1)
    disk_gb=$((disk_kb / 1024 / 1024))
    [ "$disk_gb" -ge 3 ] \
        || fail "Ruang disk tersisa ${disk_gb} GB, butuh minimal 3 GB." \
                "Bersihkan disk VPS."
    ok "ruang disk ${disk_gb} GB"

    # 4. Port bebas? (80, 443, 9110-9130) — lewati bila nginx kita sudah jalan (update)
    if ! systemctl is-active --quiet scrumspace-nginx 2>/dev/null && ! systemctl is-active --quiet nginx 2>/dev/null; then
        ports_busy=()
        for p in 80 443 $(seq 9110 9130); do
            if command -v ss >/dev/null 2>&1; then
                ss -tln 2>/dev/null | grep -qE "[:.]$p([[:space:]]|$)" && ports_busy+=("$p")
            fi
        done
        if [ "${#ports_busy[@]}" -gt 0 ]; then
            fail "Port ${ports_busy[*]} sudah dipakai layanan lain." \
                 "Matikan layanan tersebut dulu."
        fi
        ok "port 80, 443, 9110-9130 bebas"
    else
        ok "nginx sudah berjalan (skenario update) — cek port dilewati"
    fi

    # 5. systemd tersedia?
    command -v systemctl >/dev/null 2>&1 \
        || warn "systemctl tidak ditemukan — service akan dijalankan manual (nohup)."
    ok "preflight selesai"
    echo ""
    ok "SEMUA PEMERIKSAAN LOLOS — aman untuk deploy."
}

# ---------------------------------------------------------------------------
# INSTALL DEPENDENCIES
# ---------------------------------------------------------------------------
install_deps() {
    say "1/7  Install dependencies sistem..."

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq

    # PPA untuk PHP 8.5 (Ubuntu 22.04 tidak menyediakannya)
    if ! apt-cache policy php${PHP_VER}-fpm 2>/dev/null | grep -q "ondrej"; then
        say "     Tambah PPA ondrej/php untuk PHP ${PHP_VER}..."
        apt-get install -y -qq software-properties-common
        add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1
        apt-get update -qq
    fi

    say "     Install PHP ${PHP_VER} + ekstensi..."
    apt-get install -y -qq \
        php${PHP_VER}-fpm php${PHP_VER}-cli \
        php${PHP_VER}-sqlite3 php${PHP_VER}-mbstring php${PHP_VER}-xml \
        php${PHP_VER}-curl php${PHP_VER}-zip \
        nginx curl unzip git openssl

    # Composer
    if ! command -v composer >/dev/null 2>&1; then
        say "     Install Composer..."
        curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
        php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
        rm -f /tmp/composer-setup.php
    fi
    ok "PHP $(php -v | head -1 | cut -d' ' -f2), Composer, nginx terinstall"

    # Node 24 (untuk build aset frontend)
    if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -d. -f1)" != "v24" ]; then
        say "     Install Node 24..."
        curl -fsSL https://deb.nodesource.com/setup_24.x -o /tmp/nodesource.sh
        bash /tmp/nodesource.sh >/dev/null 2>&1
        apt-get install -y -qq nodejs
        rm -f /tmp/nodesource.sh
    fi
    ok "Node $(node -v)"
}

# ---------------------------------------------------------------------------
# SETUP APLIKASI
# ---------------------------------------------------------------------------
setup_app() {
    say "2/7  Siapkan kode aplikasi di ${APP_DIR}..."

    if [ -d "${APP_DIR}/.git" ]; then
        say "     Update kode (git pull)..."
        git -C "$APP_DIR" pull --ff-only
    else
        say "     Clone repo..."
        git clone https://github.com/BimaFdilana/Sistem-Scrum-AI-Team-Simulation.git "$APP_DIR"
    fi

    cd "$APP_DIR"

    # .env
    if [ ! -f .env ]; then
        cp .env.docker.example .env
        echo "     .env dibuat dari contoh."
    fi

    # Validasi variabel wajib
    missing=()
    for v in WORKSPACE_ADMIN_EMAIL WORKSPACE_ADMIN_PASSWORD; do
        grep -q "^$v=.\+" .env 2>/dev/null || missing+=("$v")
    done
    if [ "${#missing[@]}" -gt 0 ]; then
        echo ""
        echo "-----------------------------------------------------------------"
        echo " ISI DULU: Variabel wajib belum diisi di ${APP_DIR}/.env:"
        echo "   ${missing[*]}"
        echo ""
        echo " Edit filenya, lalu jalankan lagi: sudo ./deploy-native.sh"
        echo "-----------------------------------------------------------------"
        exit 1
    fi
    pass="$(grep '^WORKSPACE_ADMIN_PASSWORD=' .env | cut -d= -f2-)"
    [ "${#pass}" -ge 14 ] \
        || fail "WORKSPACE_ADMIN_PASSWORD kurang dari 14 karakter." \
                "Pakai password minimal 14 karakter di ${APP_DIR}/.env."

    # Secret otomatis bila kosong
    gen_hex() {
        grep -q "^$1=.\+" .env 2>/dev/null || {
            sed -i "s|^$1=.*|$1=$(openssl rand -hex 16)|" .env
            echo "     $1 dibuat otomatis."
        }
    }
    if ! grep -q "^APP_KEY=.\+" .env 2>/dev/null; then
        # APP_URL disesuaikan untuk native (bukan localhost Docker)
        sed -i "s|^APP_URL=.*|APP_URL=http://$(hostname -I | awk '{print $1}')|" .env
        sed -i "s|^CLIPROXY_BASE_URL=.*|CLIPROXY_BASE_URL=http://127.0.0.1:8317|" .env
        sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
        echo "     APP_KEY dibuat otomatis."
    fi
    gen_hex CLIPROXY_MANAGEMENT_KEY
    gen_hex CLIPROXY_API_KEY

    # Sesuaikan path database untuk native (bukan /app/...)
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${APP_DIR}/database/database.sqlite|" .env

    ok "konfigurasi .env siap"
}

build_app() {
    say "3/7  Build aplikasi (composer + npm)..."

    cd "$APP_DIR"

    # Direktori yang perlu writable
    mkdir -p database storage/app/public
    touch database/database.sqlite

    say "     composer install (bisa beberapa menit)..."
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet

    say "     npm install + build..."
    npm ci --no-audit --no-fund --quiet 2>/dev/null || npm install --no-audit --no-fund --quiet
    npm run build --silent

    # Permission untuk www-data (nginx + php-fpm)
    chown -R www-data:www-data storage database
    chmod -R 775 storage database

    # Storage link untuk gambar chat
    sudo -u www-data php artisan storage:link --no-interaction 2>/dev/null || true

    ok "build selesai"
}

migrate_app() {
    say "4/7  Migrasi database + seed admin..."

    cd "$APP_DIR"
    sudo -u www-data php artisan migrate --force --no-interaction

    if sudo -u www-data php artisan db:seed --force --no-interaction 2>/dev/null; then
        ok "admin workspace siap"
    else
        warn "db:seed gagal — login admin mungkin belum tersedia"
    fi

    sudo -u www-data php artisan optimize --no-interaction >/dev/null 2>&1 || true
    ok "migrasi selesai"
}

# ---------------------------------------------------------------------------
# CLIPROXYAPI (binary native + systemd)
# ---------------------------------------------------------------------------
setup_cliproxy() {
    say "5/7  Siapkan CLIProxyAPI..."

    local bindir="/usr/local/bin"
    local datadir="/var/lib/cliproxy"

    if [ ! -x "${bindir}/cli-proxy-api" ]; then
        say "     Download CLIProxyAPI v${CLIPROXY_VER}..."
        local arch="amd64"
        [ "$(uname -m)" = "aarch64" ] && arch="arm64"
        curl -fsSL -o /tmp/cliproxy.tar.gz \
            "https://github.com/router-for-me/CLIProxyAPI/releases/download/v${CLIPROXY_VER}/CLIProxyAPI_${CLIPROXY_VER}_linux_${arch}.tar.gz"
        tar -xzf /tmp/cliproxy.tar.gz -C "$bindir" cli-proxy-api
        chmod +x "${bindir}/cli-proxy-api"
        rm -f /tmp/cliproxy.tar.gz
    fi
    ok "binary CLIProxyAPI siap ($(${bindir}/cli-proxy-api --version 2>/dev/null || echo v${CLIPROXY_VER}))"

    # Config dari template
    mkdir -p "${datadir}/auths"
    if [ ! -f "${datadir}/config.yaml" ]; then
        say "     Buat config.yaml..."
        mgmt_key="$(grep '^CLIPROXY_MANAGEMENT_KEY=' "$APP_DIR/.env" | cut -d= -f2-)"
        api_key="$(grep '^CLIPROXY_API_KEY=' "$APP_DIR/.env" | cut -d= -f2-)"
        sed -e "s|\${CLIPROXY_MANAGEMENT_KEY}|${mgmt_key}|g" \
            -e "s|\${CLIPROXY_API_KEY}|${api_key}|g" \
            "$APP_DIR/docker/cliproxy/config.yaml" > "${datadir}/config.yaml"
        # Sesuaikan path untuk native
        sed -i 's|auth-dir: "/data/auths"|auth-dir: "/var/lib/cliproxy/auths"|' "${datadir}/config.yaml"
        chmod 600 "${datadir}/config.yaml"
    fi
    ok "config CLIProxyAPI siap"

    # Systemd service
    cat > /etc/systemd/system/cliproxy.service << 'EOF'
[Unit]
Description=CLIProxyAPI Gateway (ScrumSpace AI)
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/cli-proxy-api -config /var/lib/cliproxy/config.yaml
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable --now cliproxy
    ok "service cliproxy berjalan"
}

# ---------------------------------------------------------------------------
# NGINX + PHP-FPM
# ---------------------------------------------------------------------------
setup_nginx() {
    say "6/7  Konfigurasi nginx + PHP-FPM..."

    # PHP-FPM: pastikan jalan
    systemctl enable --now "php${PHP_VER}-fpm"

    # Nginx site config
    cat > /etc/nginx/sites-available/scrumspace << EOF
server {
    listen 80 default_server;
    server_name _;

    root ${APP_DIR}/public;
    index index.php;

    # Aset statis
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    }

    # Blokir akses file sensitif
    location ~ /\. {
        deny all;
    }
    location ~ ^/(\.env|composer\.(json|lock)|package\.json)\$ {
        deny all;
    }

    client_max_body_size 10M;
}
EOF
    ln -sf /etc/nginx/sites-available/scrumspace /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default

    nginx -t || fail "Konfigurasi nginx tidak valid." "Cek: nginx -t"
    systemctl enable --now nginx
    systemctl reload nginx
    ok "nginx berjalan, melayani ${APP_DIR}/public"
}

# ---------------------------------------------------------------------------
# QUEUE WORKER (systemd)
# ---------------------------------------------------------------------------
setup_worker() {
    say "7/7  Siapkan queue worker..."

    cat > /etc/systemd/system/scrumspace-queue.service << EOF
[Unit]
Description=ScrumSpace Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php${PHP_VER} ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable --now scrumspace-queue
    ok "queue worker berjalan"
}

# ---------------------------------------------------------------------------
# HEALTH CHECK
# ---------------------------------------------------------------------------
health_check() {
    say "Uji kesehatan..."
    local code=""
    for _ in $(seq 1 12); do
        code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost/ 2>/dev/null || true)
        case "$code" in 2*|3*) break ;; *) code="" ;; esac
        sleep 5
    done
    if [ -z "$code" ]; then
        warn "Aplikasi tidak menjawab HTTP setelah 60 detik."
        systemctl status nginx --no-pager 2>/dev/null | head -5 || true
        echo "--- log Laravel ---"
        tail -30 "${APP_DIR}/storage/logs/laravel.log" 2>/dev/null || true
        fail "Deploy belum sehat." \
             "Cek: systemctl status nginx php${PHP_VER}-fpm cliproxy scrumspace-queue"
    fi
    ok "aplikasi menjawab HTTP $code"
}

# ===========================================================================
# ALUR UTAMA
# ===========================================================================
need_root

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
    preflight
    exit 0
fi

preflight
install_deps
setup_app
build_app
migrate_app
setup_cliproxy
setup_nginx
setup_worker
health_check

echo ""
echo "-----------------------------------------------------------------"
echo " Selesai (native, tanpa Docker)."
echo " Buka:  http://<ip-vps>"
echo " Login: email & password admin dari ${APP_DIR}/.env"
echo " Log:   tail -f ${APP_DIR}/storage/logs/laravel.log"
echo " Servis: systemctl status nginx php${PHP_VER}-fpm cliproxy scrumspace-queue"
echo " Update: cd ${APP_DIR} && git pull && sudo ./deploy-native.sh"
echo " AI Providers: hubungkan via menu web (copy-paste OAuth seperti biasa)"
echo "-----------------------------------------------------------------"
