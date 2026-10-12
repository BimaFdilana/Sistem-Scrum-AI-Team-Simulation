# Deploy ScrumSpace ke VPS Pribadi (Docker)

Satu perintah: `./deploy.sh`. Script ini mengecek Docker, membuat `.env`,
mengisi secret otomatis, lalu build + jalan. Aman dijalankan ulang untuk update.

## 1. Prasyarat

- VPS Ubuntu 22.04/24.04 (2 vCPU, 4 GB RAM disarankan — scaffold Laravel butuh memori).
- (Opsional) Domain dengan A-record ke IP VPS untuk HTTPS otomatis.
- Port **80**, **443**, dan **9110–9130** (demo project) terbuka di firewall.

## 2. Install Docker di VPS

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# logout lalu login lagi agar grup docker aktif
```

## 3. Deploy

```bash
git clone https://github.com/BimaFdilana/Sistem-Scrum-AI-Team-Simulation.git scrumspace
cd scrumspace
./deploy.sh
```

Script akan meminta kamu mengisi `WORKSPACE_ADMIN_EMAIL` dan
`WORKSPACE_ADMIN_PASSWORD` di `.env` bila belum ada (secret lain dibuat
otomatis). Buka `http://<ip-vps>` atau `https://<domain>` bila `APP_DOMAIN`
diisi di `.env`.

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
./deploy.sh
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
