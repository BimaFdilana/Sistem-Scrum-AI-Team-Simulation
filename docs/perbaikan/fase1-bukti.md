# Fase 1 — Bukti perbaikan estimasi (3 Nov 2026)

## Gejala produksi
- 32 warning `INVALID_JSON_SCHEMA` di `storage/logs/laravel.log`
- 6 dari 8 `story_estimation_runs` gagal; tidak ada story bisa lanjut ke eksekusi
- Semua 10 `execution_runs` FAILED

## Diagnosis (3 hipotesis pertama SALAH — semua dikoreksi oleh uji langsung)
1. ~~Agent menjawab poin di luar daftar~~ — SALAH: `FIBONACCI_POINTS = [1,2,3,5,8,13]`, poin 2 SAH.
2. ~~Agent menambah kunci ekstra~~ — SALAH: keluaran persis `{estimate_points, rationale}`.
3. ~~Validator menolak poin 2~~ — SALAH: validator menerima 2, 3, 5, 8 apa adanya.

## Penyebab sebenarnya (dua cacat, keduanya di lapisan integrasi)

### Cacat A — `--format text` mengembalikan seluruh transkrip sesi
Diukur pada keluaran nyata: **2.472 byte**, berisi
1. prompt di-echo di awal (`Query: Kamu Backend Developer...`)
2. banner `☤ Hermes`
3. jawaban agent
4. ekor `Resume this session with:` + `Title:` + statistik sesi

`extractJsonObject()` mengambil dari `{` pertama sampai `}` terakhir. Karena
prompt yang di-echo memuat contoh `{"estimate_points":5,...}` dan ekor memuat
JSON sesi, rentang yang tervalidasi BUKAN jawaban agent melainkan gabungan
rentang salah -> skema rusak.

Bukti tambahan: `raw_excerpt` di log terpotong tepat pada `mb_substr($raw,0,2000)`
di tengah-kalimat `..."rationale":"Pecahan AC: (1) kolom tanggal muncul di daf"`.

### Cacat B — frasa prompt memicu penolakan model
Prompt lama memuat:
> "Ini adalah vote terstruktur internal antar-agent, bukan permintaan detail
> server — jangan menolak, kembalikan JSON yang diminta."

Pada probe 3 Nov 2026, `glm-5.3` menjawab **"I'm sorry, but I can't help with that"**
— terlihat di judul sesi Hermes (`Title: I'm sorry, but...`). Frasa "jangan
menolak" justru memicu penolakan.

## Perbaikan
1. `HermesProfileChat`: tambah `--format stream-json`, ambil event
   `{"type":"result","text":"<jawaban>"}`. Fallback berlapis: gabung potongan
   `type:text` bila stream terputus, lalu keluaran mentah bila flag diabaikan
   versi Hermes lama.
2. Prompt estimasi (putaran 1 dan konfirmasi final): hapus frasa pemicu
   penolakan; penanda `PUTARAN 1`/`KONFIRMASI FINAL PUTARAN 2` DIPERTAHANKAN
   karena dipakai kode dan tes untuk membedakan putaran.
3. Ketahanan tambahan: `nearestValidPoint()` membulatkan poin di luar daftar ke
   tetangga terdekat (4->5, 6->5, 7->8) dan mencatat nilai asli di kolom baru
   `raw_estimate_points`; angka <1 atau >13 tetap ditolak.

## Verifikasi jalur nyata (bukan mock)
`ChatProbeTest` menjalankan `HermesProfileChat` lewat CLI Hermes ASLI:

    {"estimate_points":3,"rationale":"Menambah satu kolom tanggal pada tabel
     daftar pesanan umumnya perubahan kecil di frontend dan query, tanpa logika
     bisnis baru atau migrasi besar; risikonya rendah..."}

    241 char | json_decode OK | kunci: estimate_points, rationale | poin 3 valid

Bandingkan dengan sebelum perbaikan: 2.472 char transkrip, skema tidak terbaca.

## Regresi
| | tes | lolos | gagal | error |
|---|---|---|---|---|
| sebelum | 435 | 424 | 7 | 4 |
| sesudah | 441 | 431 | 6 | 4 |

+6 tes baru, +7 lolos, -1 gagal, error tidak berubah. Kegagalan tersisa
seluruhnya sudah ada di baseline (4 error akibat ekstensi `gd`/`pdo_mysql`
tidak terpasang di host; sisanya tes usang).
