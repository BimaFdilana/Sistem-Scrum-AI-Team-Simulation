# Desain AI Team: Soul, Model, dan Skill per Peran

Disusun 2026-10-13 dari permintaan Bima. Status: **DISETUJUI & DIBANGUN
(2026-10-13)** — soul + skill diskusi selesai; pemetaan model final menunggu
OAuth tersambung di tahap test.

Tujuan: diskusi tim AI terasa seperti beda pikiran betulan, bukan satu otak tiga topeng.

---

## 1. Soul per peran

Soul = kepribadian profesional yang disuntik ke system prompt tiap agen. Bukan kosmetik: soul membentuk *bias penilaian* sehingga angka estimasi benar-benar berbeda.

### Frontend Developer — "Si Perfeksionis Pengguna"

> Kamu Frontend Developer senior yang perfeksionis soal pengalaman pengguna.
> Kamu empati pada pengguna akhir dan detail-oriented: setiap interaksi punya
> loading state, error state, dan empty state. Kamu curiga pada estimasi yang
> mengabaikan kompleksitas tampilan, dan kamu vokal membelanya.
> Gaya bicaramu konkret — bicara komponen, alur layar, dan perilaku di
> berbagai ukuran layar.

Bias: menilai TINGGI untuk interaksi kompleks, state management, responsive;
cenderung menganggap "tinggal panggil API" itu gampang.

### Backend Developer — "Si Pragmatis Minimalis"

> Kamu Backend Developer senior yang pragmatis. Prinsipmu: solusi paling
> sederhana yang benar. Kamu menilai dari sisi data, relasi, query, kontrak
> API, dan keamanan. Kamu tidak suka over-engineering dan akan menantang
> estimasi yang membesar-besarkan pekerjaan backend standar.

Bias: menilai RENDAH untuk CRUD standar; menilai TINGGI untuk integrasi pihak
ketiga, transaksi kompleks, dan masalah performa.

### QA Engineer — "Si Skeptis Konstruktif"

> Kamu QA Engineer senior yang skeptis secara konstruktif. Motomu: apa yang
> bisa rusak, akan rusak — dan tugasmu menemukannya duluan. Kamu menilai dari
> testability: jumlah skenario, edge case, dan hal yang sulit direproduksi.
> Kamu cenderung memberi angka lebih tinggi karena kamulah yang harus
> memastikan semuanya bekerja, dan kamu tidak ragu menantang asumsi FE/BE.

Bias: menilai PALING TINGGI; fokus pada "bagaimana kalau..." yang luput dari
FE/BE.

### Scrum Master — "Si Fasilitator Netral"

> Kamu Scrum Master yang netral dan adil. Kamu tidak memihak peran mana pun
> dan tidak memberi estimasi sendiri. Tugasmu: memfasilitasi mufakat, menjaga
> diskusi tetap sehat, merangkum hasil untuk PO, dan memecah story menjadi
> task saat eksekusi.

Bias: tidak ada bias angka — bias proses (konsensus > kecepatan).

---

## 2. Pemetaan model per peran

Prinsip: **beda keluarga model = beda pola pikir** (training data dan reasoning
berbeda → blind spot berbeda → perbedaan pendapat yang autentik).

| Peran | Provider (via CLIProxyAPI) | Alasan |
|---|---|---|
| Scrum Master | Model terpintar yang tersedia (prioritas: Claude / Gemini Pro) | Butuh judgment & ringkasan terbaik; netralitas penting |
| QA Engineer | Anthropic (Claude) | Teliti, kuat di analisis risiko & edge case — cocok untuk si skeptis |
| Backend Developer | Codex (GPT) | Kuat di reasoning sistem, API, dan pragmatisme teknis |
| Frontend Developer | Google (Gemini, pakai langganan Pro Bima) | All-rounder yang kuat; hemat kuota provider lain |

Aturan fallback (jika provider belum terhubung saat dipanggil):

1. Coba model utama peran tersebut.
2. Jika gagal/tidak terhubung → model mana pun yang tersedia dari akun OAuth yang sudah login.
3. Jika tidak ada → Hermes gateway (status quo), diskusi tetap jalan.

Catatan jujur: nama model persis (mis. `gemini-2.5-pro`, `claude-...`) baru bisa
dipetakan final **saat OAuth tersambung** di tahap test — daftar model dibaca
dari akun masing-masing. Tabel di atas memetakan *provider*, bukan nama model.

---

## 3. Skill per peran

Skill = kemampuan nyata agen, bukan sekadar instruksi. Dua fase:

### 3a. Skill fase DISKUSI (estimasi) — informasi asimetris

Kunci "beda pikiran": tiap agen melihat **data yang beda**, seperti tim manusia
asli. Semua read-only terhadap project milik Bima.

| Peran | Skill | Implementasi |
|---|---|---|
| FE | `component_inventory` | Scan `resources/views/components` & `resources/js` project target → ringkasan "12 komponen Blade tersedia: x, y, z" (reuse = estimasi turun) |
| FE | `route_list` | Daftar route web → tahu halaman apa saja yang tersentuh |
| BE | `schema_summary` | Parse `database/migrations` project target → ringkasan tabel, kolom, relasi (di-cache) |
| BE | `api_inventory` | Parse `routes/api.php` & controller → endpoint yang sudah ada vs harus dibuat |
| QA | `criteria_analyzer` | Prompt terstruktur: uraikan acceptance criteria jadi daftar skenario uji bernomor sebelum memberi angka |
| QA | `risk_patterns` | Baca riwayat feedback QA dari eksekusi sebelumnya ("3x BLOCKED karena validasi") sebagai pelajaran |
| SM | `consensus_tracker` | Sudah ada (mufakat bulat vs deadlock → maksimum) |
| SM | `task_breakdown` | Sudah ada (BreakdownExecutionJob) |

Contoh efek: BE melihat skema dan berkata "cuma tambah 1 kolom" (angka rendah),
sementara QA melihat 6 kriteria dan berkata "itu 6 skenario × 3 browser"
(angka tinggi) — perbedaan yang lahir dari *informasi*, bukan akting.

### 3b. Skill fase EKSEKUSI — sudah ada, tinggal selaraskan soul

| Peran | Skill (status) |
|---|---|
| FE/BE | Kirim patch JSON → sistem terapkan ke worktree (sudah ada, opsi A) |
| QA | Review diff + feedback terstruktur (sudah ada, maks 2 revisi) |
| SM | Breakdown task + ringkasan progress ke PO (sudah ada) |

Soul ikut dipakai di fase eksekusi: prompt FE/BE/QA disuntik soul masing-masing
sehingga gaya kerja konsisten dengan gaya diskusinya.

---

## 4. Bentuk implementasi (rencana teknis)

- `config/agents.php` baru: per peran → `provider`, `model`, `fallback_models`,
  `soul` (teks system prompt), `skills` (daftar skill diskusi).
- `AgentProfileClient` dan prompt estimasi membaca soul dari config (bukan
  hardcode di service).
- `App\Support\Workspace\ProjectInspector` baru: `componentInventory()`,
  `schemaSummary()`, `apiInventory()` — scan read-only project target, di-cache
  per project.
- `StoryEstimationService::agentPrompt()` menambahkan konteks skill sesuai peran
  (asymmetric info).
- Pemetaan model final diisi saat OAuth tersambung (tahap test).

Yang TIDAK diubah: aturan konsensus (mufakat/deadlock→maksimum), Fibonacci murni,
izin sekali via Accepted, QA maks 2 revisi.

---

## 5. Pertanyaan untuk Bima

1. **Pemetaan model** — setuju QA=Claude, BE=Codex, FE=Gemini, SM=terpintar?
   Atau ada preferensi lain (mis. semua Gemini Pro saja)?
2. **Intensitas soul** — usulan di atas level "profesional berkarakter".
   Mau lebih galak (QA super nyinyir) atau lebih kalem?
3. **Skill baca file project** — setuju agen membaca (read-only, tanpa ubah)
   skema DB & daftar komponen project-mu saat diskusi estimasi?
4. **Urutan bangun** — usulan: soul + skill diskusi dibangun SEKARANG
   (tidak butuh OAuth), pemetaan model final + wiring CLIProxyAPI di tahap test.
   Setuju?
