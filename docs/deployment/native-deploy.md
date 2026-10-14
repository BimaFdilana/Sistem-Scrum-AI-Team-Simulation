# Deploy ScrumSpace ke VPS Tanpa Docker (Native)

Untuk VPS yang **tidak bisa menjalankan Docker** (kernel terbatas, container bersarang, dll).

Satu perintah: `sudo ./deploy-native.sh`

## Yang Diinstall

| Komponen | Cara |
|----------|------|
| PHP 8.5 + FPM | PPA `ondrej/php` |
| Ekstensi PHP | `sqlite3`, `mbstring`, `xml`, `curl`, `zip` |
| Composer | getcomposer.org resmi |
| Node 24 | NodeSource |
| nginx | apt |
| CLIProxyAPI | Binary resmi GitHub releases |

## Yang Dijalankan

| Service | Peran |
|---------|------|
| `nginx` | Web server (port 80) |
| `php8.5-fpm` | Eksekusi PHP |
| `cliproxy` | Gateway OAuth AI (port 8317, hanya localhost) |
| `scrumspace-queue` | Worker antrean Laravel |

## Lokasi

- Kode: `/opt/scrumspace`
- Database: `/opt/scrumspace/database/database.sqlite`
- Config CLIProxyAPI: `/var/lib/cliproxy/config.yaml`
- Token OAuth: `/var/lib/cliproxy/auths/`
- Log: `/opt/scrumspace/storage/logs/laravel.log`

## Cara Pakai

```bash
# 1. Clone
git clone https://github.com/BimaFdilana/Sistem-Scrum-AI-Team-Simulation.git scrumspace
cd scrumspace

# 2. Preflight (cek saja)
sudo ./deploy-native.sh --preflight

# 3. Deploy
sudo ./deploy-native.sh
# → berhenti bila WORKSPACE_ADMIN_EMAIL/PASSWORD belum diisi di /opt/scrumspace/.env
# → isi, lalu jalankan lagi

# 4. Buka http://<ip-vps> di browser
```

## Perintah Berguna

```bash
# Status semua service
systemctl status nginx php8.5-fpm cliproxy scrumspace-queue

# Restart setelah update kode
cd /opt/scrumspace && git pull && sudo ./deploy-native.sh

# Log aplikasi
tail -f /opt/scrumspace/storage/logs/laravel.log

# Log CLIProxyAPI
journalctl -u cliproxy -f
```

## Perbedaan vs Docker

| | Docker | Native |
|---|---|---|
| Isolasi | Penuh (container) | Berbagi OS dengan service lain |
| PHP | Di dalam image | Terinstall di sistem |
| Update | `git pull && ./deploy.sh` | `git pull && sudo ./deploy-native.sh` |
| Backup | Otomatis ke `backup/` | Manual (copy `database.sqlite` + `/var/lib/cliproxy/`) |

## Catatan

- Script harus dijalankan sebagai **root** (sudo).
- Hanya untuk **Ubuntu 22.04/24.04**.
- Untuk HTTPS, tambahkan reverse proxy (Caddy/Cloudflare) di depan, atau konfigurasi certbot manual.
