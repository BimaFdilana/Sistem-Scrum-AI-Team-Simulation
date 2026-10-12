# Landasan Website Scrum AI Team Simulation

> Dokumen fondasi — ditulis 2026-10-11 berdasarkan arahan Product Owner (Bima).
> Semua perubahan website mengacu ke dokumen ini. Bagian bertanda **[PUTUSAN]**
> masih menunggu keputusan PO.

## 1. Peran

| Peran | Dilakukan oleh |
|---|---|
| Product Owner (PO) | Manusia (Bima) — satu-satunya user website |
| Scrum Master (SM) | AI agent — orkestrasi |
| Frontend Developer (FE) | AI agent — implementasi UI |
| Backend Developer (BE) | AI agent — implementasi server |
| QA Engineer (QA) | AI agent — verifikasi independen |

## 2. Alur end-to-end yang diinginkan

1. **Buat project** — PO membuat project Laravel baru *atau* clone project yang sudah ada
   (via URL GitHub). Upload zip **tidak dipakai**.
2. **Buat backlog** untuk project tersebut.
3. **Buat sprint** dan **user story** di dalamnya.
4. **Tombol Fibonacci** di sprint backlog → SM memerintahkan FE, BE, QA menilai
   user story dengan poin `1, 2, 3, 5, 8, 13`.
5. Tiap tim memberi nilai → **SM melaporkan ke PO via chat**: nilai tiap tim +
   **Estimasi Poin = nilai maksimum** (contoh: FE=2, BE=4, QA=1 → estimasi 4).
   - Status: 1,2 = Ringan; 5,8 = Sedang; 8,13 = Berat **[PUTUSAN: angka 8 ganda, angka 3 belum dipetakan]**
6. Tiap tim punya **activity log** hasil diskusi + **halaman chat diskusi tim**
   (read-only untuk PO — PO tidak bisa mengirim chat di sana).
7. **Tombol Eksekusi User Story** di samping tombol Fibonacci — aktif hanya jika
   Estimasi Poin sudah tampil di user story.
8. **Eksekusi**: SM membagikan tugas ke tiap AI agent. FE & BE mengerjakan code,
   lalu menyerahkan ke QA. Jika ada revisi dari QA, FE/BE langsung memperbaiki
   sampai Planning User Story selesai.
9. **Riwayat revisi** FE/BE dengan QA ditampilkan sebagai chat + activity log.
10. User story selesai → QA menyerahkan ke SM → SM membuat **Ringkasan Progress**.
11. Ringkasan Progress SM ditampilkan ke PO **via chat SM–PO**.
12. Semua **permintaan izin** (dari BE/FE/QA/SM yang butuh tindakan khusus)
    tampil di **menu Accepted** agar PO mudah memberi izin.
13. Halaman project: tombol **Demo Project** (URL sementara) agar PO melihat
    perubahan langsung + tombol **Download Project (zip)**.

## 3. Kondisi saat ini vs target

Sudah ada: buat project (GitHub import + zip), backlog → sprint → story,
tombol Fibonacci + diskusi 3-agent, halaman diskusi read-only untuk PO,
activity log per agent, laporan SM via job, chat SM–PO.

Belum ada / berbeda:
- Aturan estimasi saat ini = **konsensus 3 putaran** (ketiga agent wajib sepakat
  angka sama; maksimum/rata-rata dilarang eksplisit di `docs/fibonacci-consensus.md`).
  Target = **nilai maksimum**. **[PUTUSAN]**
- Tombol Eksekusi User Story + pipeline eksekusi (SM bagi tugas → FE/BE code →
  QA review → loop revisi → ringkasan SM). **Belum ada sama sekali.**
- Menu Accepted/Approval. **Belum ada.**
- Tombol Demo (URL sementara) + Download zip di halaman project. **Belum ada.**
- Opsi upload zip di form project **akan dihapus**.

## 4. Arsitektur AI provider (lepas dari Hermes)

- Role/profile (SOUL, instructions, skills) tetap di Laravel — tidak terikat Hermes.
- Dibuat interface `AIProvider` dengan implementasi:
  `GeminiProvider`, `OpenAIProvider`, `AnthropicProvider`, `HermesProvider` (legacy).
- Gemini dipakai via endpoint resmi yang kompatibel OpenAI:
  `https://generativelanguage.googleapis.com/v1beta/openai/` — cukup API key,
  **tanpa OAuth**.
- Halaman `/agents`: pilih **provider + model per role** (saat ini hanya model
  dari katalog Hermes).
- API key disimpan di `.env` (`GEMINI_API_KEY`, `OPENAI_API_KEY`,
  `ANTHROPIC_API_KEY`), tidak di database/UI.
- **[PUTUSAN]**: provider pertama (usulan: Gemini), ketersediaan API key,
  boleh beda provider per role?, nasib Hermes (fallback vs hapus total).

## 5. Fase pengerjaan (usulan)

- **Fase 1**: Aturan estimasi baru + status poin + tombol Eksekusi (dengan gating).
- **Fase 2**: AIProvider abstraction + Gemini sebagai provider pertama.
- **Fase 3**: Pipeline eksekusi + loop revisi QA + ringkasan SM.
- **Fase 4**: Menu Accepted + Demo + Download zip + hapus upload zip.

## 6. Pertanyaan terbuka untuk PO

1. Estimasi: ganti total ke sistem **maksimum**, atau pertahankan konsensus 3 putaran?
2. Pemetaan status final untuk 3 dan 8? (Usulan: 1,2,3 = Ringan; 5,8 = Sedang; 13 = Berat.)
3. Poin wajib dari list Fibonacci, atau boleh angka bebas (contoh: 4)?
4. Saat eksekusi: FE/BE langsung menulis file project, atau buat patch yang PO setujui dulu di menu Accepted?
5. Website di-deploy di mana? (Menentukan cara kerja tombol Demo.)
6. Push ke GitHub: langsung ke `main` atau via feature branch + PR?
7. Provider AI pertama + API key sudah ada? Boleh beda provider per role? Hermes dipertahankan?
8. Klarifikasi alur saat ini: story dibuat di backlog dulu atau langsung di sprint? Laporan SM muncul di chat mana? "Eksekusi terkunci" mengunci apa?
