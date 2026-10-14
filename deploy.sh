#!/usr/bin/env bash
# ===========================================================================
# ScrumSpace — deploy satu perintah ke VPS.
#   ./deploy.sh              Deploy penuh (pre-flight -> backup -> build -> cek)
#   ./deploy.sh --preflight  Hanya pemeriksaan, tanpa mengubah apa pun.
#
# Aman dijalankan ulang untuk update (git pull manual sebelum update).
# Semua pesan error dalam Bahasa Indonesia + cara memperbaikinya.
# ===========================================================================
set -euo pipefail
cd "$(dirname "$0")"

PREFLIGHT_ONLY=0
[ "${1:-}" = "--preflight" ] && PREFLIGHT_ONLY=1

say()  { echo "==> $1"; }
ok()   { echo "    [OK] $1"; }
warn() { echo "    [!!] $1"; }

fail() { # $1=judul $2=cara memperbaiki (opsional)
    echo ""
    echo "-----------------------------------------------------------------"
    echo " GAGAL: $1"
    [ -n "${2:-}" ] && echo " PERBAIKI: $2"
    echo "-----------------------------------------------------------------"
    exit 1
}

# ---------------------------------------------------------------------------
# PRE-FLIGHT CHECK — semua masalah umum ditangkap di sini, SEBELUM ada yang
# diubah. Kalau lolos, deploy hampir pasti mulus.
# ---------------------------------------------------------------------------
preflight() {
    say "Pre-flight check..."

    # 1. Docker terinstall?
    command -v docker >/dev/null 2>&1 \
        || fail "Docker belum terinstall." \
                "Jalankan:  curl -fsSL https://get.docker.com | sh"
    ok "docker terinstall ($(docker --version 2>/dev/null | head -1))"

    # 2. Daemon Docker berjalan?
    docker info >/dev/null 2>&1 \
        || fail "Daemon Docker tidak berjalan atau user ini tidak punya akses." \
                "Coba: sudo systemctl start docker  |  atau: sudo usermod -aG docker \$USER lalu logout-login lagi."
    ok "daemon docker berjalan"

    # 3. Docker Compose plugin?
    docker compose version >/dev/null 2>&1 \
        || fail "Plugin Docker Compose tidak ditemukan." \
                "Install ulang Docker dari https://get.docker.com (versi baru sudah termasuk compose)."
    ok "docker compose tersedia"

    # 4. Perintah pendukung
    for c in openssl curl; do
        command -v "$c" >/dev/null 2>&1 \
            || fail "'$c' tidak ditemukan." \
                    "Install: sudo apt update && sudo apt install -y $c"
    done
    ok "openssl & curl tersedia"

    # 5. Ruang disk cukup? (butuh ~3 GB untuk image + build)
    # (|| true di dalam substitusi: dengan pipefail, df yang gagal membuat
    # status substitusi non-nol dan set -e langsung mematikan script —
    # fallback di baris berikut tidak akan pernah tercapai tanpanya.)
    disk_kb=$(df -k --output=avail /var/lib/docker 2>/dev/null | tail -1 || true)
    [ -n "$disk_kb" ] || disk_kb=$(df -k --output=avail / | tail -1)
    disk_gb=$((disk_kb / 1024 / 1024))
    [ "$disk_gb" -ge 3 ] \
        || fail "Ruang disk tersisa ${disk_gb} GB, butuh minimal 3 GB." \
                "Bersihkan dengan: docker system prune -a  |  atau tambah kapasitas disk VPS."
    ok "ruang disk ${disk_gb} GB"

    # 6. RAM (peringatan saja, tidak menggagalkan)
    mem_gb=$(free -g 2>/dev/null | awk '/^Mem:/{print $2}')
    if [ -n "$mem_gb" ] && [ "$mem_gb" -lt 3 ]; then
        warn "RAM ${mem_gb} GB (disarankan >= 4 GB). Build bisa lambat tapi tetap jalan."
    else
        ok "RAM ${mem_gb:-?} GB"
    fi

    # 7. Git bersih? (deploy dari kode yang belum di-commit = hasil tak terduga)
    if [ -d .git ]; then
        git diff --quiet && git diff --cached --quiet \
            || fail "Ada perubahan kode yang belum di-commit." \
                    "Jalankan: git add -A && git commit -m \"...\"  |  atau: git stash"
        ok "git bersih ($(git rev-parse --short HEAD))"
    fi

    # 8. Port bebas? (dilewati bila stack kita sendiri sedang berjalan = skenario update)
    if [ -z "$(docker compose ps --status running -q 2>/dev/null)" ]; then
        ports_busy=()
        for p in 80 443 $(seq 9110 9130); do
            if command -v ss >/dev/null 2>&1; then
                ss -tln 2>/dev/null | grep -qE "[:.]$p([[:space:]]|$)" && ports_busy+=("$p")
            elif command -v netstat >/dev/null 2>&1; then
                netstat -tln 2>/dev/null | grep -qE "[:.]$p([[:space:]]|$)" && ports_busy+=("$p")
            fi
        done
        if [ "${#ports_busy[@]}" -gt 0 ]; then
            fail "Port ${ports_busy[*]} sudah dipakai layanan lain." \
                 "Matikan layanan tersebut dulu, atau cek dengan: ss -tlnp | grep -E ':(${ports_busy[0]}) '"
        fi
        ok "port 80, 443, 9110-9130 bebas"
    else
        ok "stack lama sedang berjalan (skenario update) — cek port dilewati"
    fi

    # 9. Konfigurasi compose valid? (tangkap salah tulis YAML/.env SEBELUM build)
    docker compose config -q >/dev/null 2>&1 \
        || fail "docker-compose.yml / .env tidak valid." \
                "Jalankan: docker compose config  untuk melihat baris yang bermasalah."
    ok "konfigurasi docker compose valid"

    # 10. Variabel wajib terisi
    # (Mode --preflight tidak membuat file apa pun, jadi .env yang belum ada
    # dilaporkan di sini; pada mode deploy penuh .env sudah dibuat sebelum
    # preflight berjalan.)
    [ -f .env ] \
        || fail "File .env belum ada." \
                "Salin dari contoh:  cp .env.docker.example .env  |  lalu isi WORKSPACE_ADMIN_EMAIL & WORKSPACE_ADMIN_PASSWORD di dalamnya."
    missing=()
    for v in WORKSPACE_ADMIN_EMAIL WORKSPACE_ADMIN_PASSWORD; do
        grep -q "^$v=.\+" .env 2>/dev/null || missing+=("$v")
    done
    if [ "${#missing[@]}" -gt 0 ]; then
        fail "Variabel wajib belum diisi di .env: ${missing[*]}" \
             "Isi nilainya di file .env, lalu jalankan ./deploy.sh lagi."
    fi
    ok "variabel wajib terisi"

    echo ""
    ok "SEMUA PEMERIKSAAN LOLOS — aman untuk deploy."
}

# ---------------------------------------------------------------------------
# BACKUP — simpan data (database + storage + token OAuth) sebelum diubah.
# ---------------------------------------------------------------------------
backup_data() {
    # Nama volume docker compose = <nama-folder>_<nama-volume>
    project=$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')
    vols=$(docker volume ls -q 2>/dev/null | grep "^${project}_" || true)
    [ -z "$vols" ] && return 0
    mkdir -p backup
    f="backup/deploy-$(date +%F_%H%M%S).tar.gz"
    say "Backup data -> $f"
    args=()
    for v in $vols; do args+=(-v "$v:/v/$v"); done
    if docker run --rm "${args[@]}" -v "$PWD/backup:/backup" \
        alpine tar czf "/backup/$(basename "$f")" -C / v 2>/dev/null; then
        ok "backup tersimpan"
    else
        warn "backup gagal — lanjut deploy tanpa backup (data lama tetap di volume)."
    fi
}

# ---------------------------------------------------------------------------
# HEALTH CHECK — pastikan aplikasi benar-benar menjawab setelah up.
# ---------------------------------------------------------------------------
health_check() {
    say "Uji kesehatan aplikasi (maks ~60 detik)..."
    code=""
    for _ in $(seq 1 12); do
        code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost/ 2>/dev/null || true)
        case "$code" in 2*|3*) break ;; *) code="" ;; esac
        sleep 5
    done
    if [ -z "$code" ]; then
        echo ""
        warn "Aplikasi tidak menjawab HTTP setelah 60 detik. Diagnosa cepat:"
        docker compose ps 2>/dev/null || true
        echo "--- 30 baris log terakhir (app) ---"
        docker compose logs --tail=30 app 2>/dev/null || true
        fail "Deploy belum sehat." \
             "Lihat log: docker compose logs -f app  |  Kembali ke versi sebelumnya: ./rollback.sh"
    fi
    ok "aplikasi menjawab HTTP $code"
}

# ===========================================================================
# ALUR UTAMA
# ===========================================================================
if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
    preflight
    exit 0
fi

say "1/6  Pre-flight check..."
# .env harus sudah ada sebelum pre-flight: cek variabel wajib butuh file-nya.
# (Mode --preflight murni memeriksa; ia tidak membuat file, jadi kasus .env
# belum ada ditangani pesannya di dalam preflight().)
[ -f .env ] || { cp .env.docker.example .env; echo "     .env dibuat dari contoh."; }
preflight

say "2/6  Siapkan secret..."
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

say "3/6  Catat titik rollback..."
if [ -d .git ]; then
    echo "PREV_COMMIT=$(git rev-parse HEAD)" > .deploy-state
    echo "DEPLOYED_AT=$(date -u +%FT%TZ)" >> .deploy-state
    ok "titik rollback: $(git rev-parse --short HEAD)"
fi

say "4/6  Backup data (bila ada)..."
backup_data

say "5/6  Build & jalankan (bisa beberapa menit saat pertama kali)..."
docker compose up -d --build

say "6/6  Status & uji kesehatan..."
sleep 6
docker compose ps
echo ""
health_check

echo ""
echo "-----------------------------------------------------------------"
echo " Selesai."
echo " Buka:  http://<ip-vps>  atau  https://<domain> (bila APP_DOMAIN diisi)"
echo " Login: email & password admin dari .env"
echo " Log:   docker compose logs -f app"
echo " Update berikutnya:  git pull && ./deploy.sh"
echo " Kembali ke versi sebelumnya:  ./rollback.sh"
echo "-----------------------------------------------------------------"
