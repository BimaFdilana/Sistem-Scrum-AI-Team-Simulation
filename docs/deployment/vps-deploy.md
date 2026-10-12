# Deploy ScrumSpace ke VPS Pribadi (Docker)

Panduan ini memasang **Laravel + CLIProxyAPI** di VPS dengan satu perintah.
Arsitektur: 3 container — `app` (website), `worker` (antrean job), `cliproxy`
(gateway OAuth AI). HTTPS otomatis bila kamu punya domain.

## 1. Prasyarat

- VPS Ubuntu 22.04/24.04 (1 vCPU, 2 GB RAM cukup untuk pribadi).
- (Opsional tapi disarankan) Domain yang A-record-nya mengarah ke IP VPS,
  mis. `scrum.namadomain.com` — untuk HTTPS otomatis.
- Port **80** dan **443** terbuka di firewall VPS.

## 2. Install Docker di VPS

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# logout lalu login lagi agar grup docker aktif
docker --version
docker compose version
```

## 3. Ambil source & siapkan konfigurasi

```bash
git clone https://github.com/BimaFdilana/Sistem-Scrum-AI-Team-Simulation.git scrumspace
cd scrumspace
cp .env.docker.example .env
nano .env
```

Isi yang wajib di `.env`:

| Variabel | Isi |
|---|---|
| `APP_DOMAIN` | Domain kamu (mis. `scrum.namadomain.com`) — kosongkan bila akses via IP (HTTP saja) |
| `APP_URL` | `https://scrum.namadomain.com` (atau `http://<ip-vps>`) |
| `WORKSPACE_ADMIN_EMAIL` / `WORKSPACE_ADMIN_PASSWORD` | Login admin pertama (password min. 14 karakter) |
| `CLIPROXY_MANAGEMENT_KEY` | String acak, mis. hasil `openssl rand -hex 16` |
| `CLIPROXY_API_KEY` | String acak lain, mis. hasil `openssl rand -hex 16` |

`APP_KEY` boleh dikosongkan — dibuat otomatis saat pertama jalan
(lihat log `app`, lalu salin ke `.env` agar permanen).

## 4. Jalankan

```bash
docker compose up -d --build
```

Tunggu ±1 menit (build pertama mengunduh image + install dependency),
lalu cek:

```bash
docker compose ps
docker compose logs -f app   # lihat proses migrasi & APP_KEY bila di-generate
```

Buka `http://<ip-vps>` atau `https://<domain>` → halaman login muncul.

## 5. Hubungkan AI Provider (OAuth)

1. Login sebagai admin → menu **AI Providers**.
2. Klik **Hubungkan** pada provider (Google/Codex/Claude) → salin **link login**
   yang tampil.
3. Buka link itu di browser HP/laptop kamu → login akun → klik **Allow/Izinkan**.
4. Browser akan mencoba membuka `http://localhost:51121/...` dan **gagal
   load — itu normal**. Copy **seluruh URL dari address bar** (mengandung
   `?code=...`).
5. Tempel URL tersebut ke form di website → klik simpan. Selesai.

Token OAuth tersimpan di volume `cliproxy-data`, jadi tidak hilang saat
container di-restart.

## 6. Update ke versi baru

```bash
cd scrumspace
git pull
docker compose up -d --build
```

## 7. Backup

Yang perlu di-backup hanyalah volume Docker:

```bash
docker run --rm \
  -v scrumspace_app-database:/db \
  -v scrumspace_cliproxy-data:/cp \
  -v $(pwd)/backup:/backup \
  alpine tar czf /backup/backup-$(date +%F).tar.gz -C / db cp
```

## 8. Troubleshooting

| Gejala | Periksa |
|---|---|
| Halaman tidak terbuka | `docker compose ps` — semua `running`? Firewall VPS membuka 80/443? |
| `Gagal meminta link login dari gateway` | `docker compose logs cliproxy` — service mati? `docker compose restart cliproxy` |
| HTTPS tidak terbit | `APP_DOMAIN` benar & mengarah ke IP VPS? Tunggu 1–2 menit setelah start |
| Lupa password admin | `docker compose exec app php artisan tinker` → update manual user |
| Ingin lihat log | `docker compose logs -f [app\|worker\|cliproxy]` |
