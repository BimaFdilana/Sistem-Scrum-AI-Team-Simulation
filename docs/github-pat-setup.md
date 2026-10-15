# Panduan: Membuat Personal Access Token (PAT) GitHub untuk ScrumSpace

ScrumSpace terhubung ke GitHub kamu memakai **Personal Access Token (PAT)** — bukan password GitHub. Token ini memberi website izin terbatas untuk membuat repo, fork, branch, dan merge **atas namamu**.

Waktu yang dibutuhkan: ± 5 menit.

---

## Langkah 1 — Buka halaman token GitHub

1. Login ke [github.com](https://github.com) di browser.
2. Klik foto profil (pojok kanan atas) → **Settings**.
3. Scroll ke bawah → **Developer settings** (menu paling bawah di sidebar kiri).
4. Pilih **Personal access tokens** → **Tokens (classic)**.

[screenshot: halaman Developer settings > Personal access tokens > Tokens (classic)]

---

## Langkah 2 — Buat token baru

1. Klik **Generate new token** → **Generate new token (classic)**.
2. Di kolom **Note**, isi: `ScrumSpace`
3. Di **Expiration**, pilih sesuai kebutuhan (mis. `90 days` atau `No expiration` untuk test).
4. Pada daftar **Select scopes**, centang:
   - ✅ **`repo`** (full control of private repositories) — ini yang utama
   - Tidak perlu centang yang lain.
5. Klik **Generate token** di bawah.

[screenshot: form generate token dengan scope repo tercentang]

> ⚠️ **PENTING:** Token hanya ditampilkan **sekali** di layar berikutnya. Kalau halamannya tertutup sebelum di-copy, kamu harus buat token baru.

---

## Langkah 3 — Copy token ke ScrumSpace

1. Copy token yang tampil (diawali `ghp_`).
2. Buka website ScrumSpace → menu **GitHub** (sidebar).
3. Tempel token di kolom **Personal Access Token**.
4. Klik **Test Koneksi** — harusnya muncul `Terhubung sebagai @username-kamu`.
5. Kalau berhasil, klik **Simpan & Hubungkan**.

[screenshot: halaman Settings > GitHub di ScrumSpace dengan tombol Test Koneksi]

---

## Langkah 4 — Selesai

Status di halaman berubah jadi **Terhubung** dengan username GitHub-mu. Website sekarang bisa:

- Membuat repo baru di akunmu (untuk project baru)
- Fork repo (untuk project yang di-clone dari repo orang lain)
- Membuat branch per user story
- Merge branch ke branch `integration`

---

## Keamanan

- **Token = password.** Jangan bagikan ke siapa pun, jangan tempel di chat/email.
- Token disimpan **terenkripsi** di database website (tidak pernah ditampilkan lagi setelah disimpan).
- Kamu bisa **mencabut** token kapan pun: github.com → Settings → Developer settings → Personal access tokens → klik **Delete** di token `ScrumSpace`. Integrasi langsung terputus.
- Kalau token bocor (mis. tidak sengaja ter-copy ke tempat umum), segera hapus dan buat yang baru, lalu update di halaman GitHub ScrumSpace.

---

## Masalah umum

| Gejala | Penyebab & solusi |
|--------|-------------------|
| Test Koneksi gagal: "Tidak terhubung" | Token salah copy (kurang/lebih karakter) atau scope `repo` belum dicentang. Buat token baru. |
| "Token tidak valid" saat simpan | Sama seperti di atas — pastikan seluruh token ter-copy termasuk `ghp_` di depan. |
| Bisa connect tapi gagal buat repo | Scope `repo` kurang. Token classic butuh centang `repo` penuh, bukan sebagian. |
