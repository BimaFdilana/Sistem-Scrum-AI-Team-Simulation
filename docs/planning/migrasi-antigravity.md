# TODO: Migrasi Tim Agen ke Antigravity CLI (`agy`)

> Dibuat 2026-11-19 atas keputusan PO (Bima). Tujuan: mengganti **Hermes, OpenCode, dan
> CLIProxy** dengan **Antigravity CLI** untuk semua peran agen (SM, BE, FE, QA), tanpa
> kehilangan memori sprint. Login akun Google dilakukan dari halaman **AI Model**.
>
## Status 19 Nov 2026: migrasi selesai, layanan lama sudah dihapus

- **Rilis:** `459771e` + perbaikan `1a1b356` di-deploy ke scrum-team. Doctor di production lolos
  semua (Antigravity 1.5.12, login Google, 6 model, model aktif `gemini-3.8-flash-high` menjawab).
- **Sudah jalan di Antigravity:** chat SM (termasuk gambar), breakdown, estimasi Fibonacci,
  eksekusi BE/FE di worktree (profil `scrumspace-backend`/`scrumspace-frontend`), QA batch,
  dialog temuan, pemantau kuota, halaman AI Model (login Google dari web, model utama + per peran).
- **Memori:** `ProjectMemory` menyusun keputusan PO, temuan QA, dan riwayat git menjadi
  `.agents/rules/memori-proyek.md` di tiap worktree (`php artisan memori:ekspor`).
  Memori Hermes lama dicadangkan di `~/backup-migrasi-antigravity-20261119/`.
- **Dihapus dari server (19 Nov):** `cliproxy.service`, `/usr/local/bin/cli-proxy-api`,
  `/var/lib/cliproxy` (port 8317 tertutup); OpenCode (`opencode-ai` global npm, data dan cache,
  `.opencode-home`); Hermes (`~/.hermes`, gateway `hermes-gateway.service`, launcher di
  `~/.local/bin`). Disk 88% → 55%. Kode lama sudah dihapus di rilis `459771e`.
- **Belum dikerjakan (lanjutan, opsional):** keluaran terstruktur `--json-schema` (Fase 1/4),
  semaphore paralel, Fase 6 (slash command), pilihan jenis akun Cloud/API key, uji login web
  dalam keadaan logout, dan jalur Docker (`deploy.sh`, `docker-compose.yml`) yang masih
  menyebut CLIProxy.
- **Masih di tangan PO (butuh root):** hapus `/etc/sudoers.d/scrumspace-hermes` dan baris
  `HERMES_*`/`CLIPROXY_*`/`OPENCODE_*` di `/opt/scrumspace/.env`.

Checklist di bawah adalah rencana awal; status sebenarnya ada di ringkasan di atas.

> Aturan kerja (saat migrasi): semua perubahan di `~/scrumspace-dev` pada branch `feat/antigravity`.
> **Jangan push ke `main` sebelum Fase 7**, karena push `main` otomatis men-deploy scrum-team.

## Fakta yang sudah diverifikasi di VPS

- `agy` 1.5.12 terpasang di `/home/sidik/.local/bin/agy` (installer resmi, checksum OK).
- Login Google OAuth berhasil. Alurnya URL lalu kode ("Paste this code into your
  application…"). Kredensial disimpan berbasis file di `~/.config/antigravity/`, tanpa keyring.
- Headless jalan: `agy -p "…" --model <slug> --output-format json --print-timeout 90s`
  mengembalikan `conversation_id`, `status`, `response`, `usage{input,output,thinking,total}`.
  Sekitar 8 detik per panggilan sederhana.
- Profil role jalan: `.agents/agents/<role>/agent.md` + `--agent <role>`. Profil kecil juga
  menghemat token (sekitar 2,3k input, dibanding 9,6k tanpa profil).
- Model yang tersedia untuk akun ini: `gemini-3.8-flash-{high,medium,low}`,
  `claude-sonnet-4-6`, `claude-opus-4-6-thinking`, `gpt-oss-120b-medium`.
  Gemini 4 dan Claude 5.5 tidak muncul (kemungkinan akun belum Pro).
- Flag penting: `--agent`, `--model`, `--effort`, `--mode accept-edits|plan`, `--settings`
  (JSON izin per panggilan), `--json-schema`, `--print-timeout`, `--conversation`,
  `--disable-slash-commands`, `--add-dir`.

## Risiko yang sudah diketahui

- [ ] **ToS Google:** FAQ resmi menyebut membungkus `agy` di dalam aplikasi "not supported
      under consumer plans". PO memilih tetap memakai akun Google. Mitigasi: pakai akun
      Google terpisah, dan halaman AI Model tetap menyediakan opsi Google Cloud project
      dan Gemini API key.
- [ ] Claude 4.6 dan GPT-OSS dijadwalkan dihapus Google (doc: 18 Nov 2026). Pastikan model
      cadangan per peran selalu Gemini.
- [ ] Belum diketahui apakah beberapa proses `agy` boleh paralel dengan satu akun. Uji di Fase 1.
- [ ] VPS 1 vCPU / 2,2 GB RAM: batasi worker agen paralel (mulai dari 1).

---

## Fase 0: Persiapan

- [ ] Buat branch `feat/antigravity` di `~/scrumspace-dev`.
- [ ] Backup: `database.sqlite` ScrumSpace, `~/.hermes/profiles/*/memories`, `/var/lib/cliproxy/config.yaml`.
- [ ] Catat konfigurasi lama (model per peran, `EXECUTION_DRIVER`, `OPENCODE_*`, `CLIPROXY_*`) untuk rollback.
- [ ] Sudoers wrapper: `www-data` menjalankan `agy` sebagai `sidik` (pola sama dengan
      wrapper Hermes), karena login tersimpan di home `sidik`.
      Selesai bila: `sudo -u www-data <wrapper> models` menampilkan daftar model.

## Fase 1: Lapisan klien Antigravity (backend)

- [ ] `config/ai.php`: blok `antigravity` (binary, wrapper, timeout, model default per
      peran, maksimal proses paralel). Driver baru `EXECUTION_DRIVER=antigravity`.
- [ ] `App\Services\AntigravityClient`: jalankan `agy -p` lewat Symfony Process, parse
      JSON, petakan `status` dan exit code ke error berbahasa Indonesia, catat `usage` ke
      `ai_usage_records` (provider `Antigravity`).
- [ ] Mode terstruktur: `--json-schema` untuk keluaran SM (breakdown, estimasi) dan QA (bukti per kriteria).
- [ ] Klasifikasi error ke `AiGateway` yang sudah ada: belum login, kuota habis, timeout,
      model tidak dikenal. Error sementara = release job, bukan gagalkan run.
- [ ] Kunci paralel (semaphore) sesuai `maks proses`. Uji 2 proses bersamaan dengan 1 akun.
- [ ] Tes unit dengan binary palsu (fake `agy` yang mengeluarkan JSON contoh).

## Fase 2: Profil role (Tim Agen)

Lokasi: `storage/app/private/agy-agents/` (disalin ke `.agents/agents/` tiap workspace
proyek saat run dimulai), atau global `~/.gemini/config/agents/`.

- [ ] `scrum-master/agent.md`: refinement, breakdown (wajib petakan tiap acceptance
      criteria ke task), merangkum estimasi, menengahi temuan QA. Tools baca saja.
- [ ] `backend-developer/agent.md`: kerja di worktree task, boleh `write_file`, `php -l`,
      `php artisan test`. Tidak boleh `git push`, `rm -rf`, atau `sudo`.
- [ ] `frontend-developer/agent.md`: Blade, Tailwind, Vite. Izin sama dengan BE plus `npm run build`.
- [ ] `qa-engineer/agent.md`: baca diff dan kriteria, jalankan tes, **tidak boleh menulis
      file**. Keluaran JSON `criteria[{id, met, evidence}]`.
- [ ] Izin per peran lewat `--settings` (`permissions.allow/deny`), karena izin per agen
      tidak didukung di frontmatter.
- [ ] Rules bersama: `AGENTS.md` (konvensi proyek) dan `.agents/rules/*.md` (`trigger: always_on`).
- [ ] Uji: tiap peran dipanggil dengan `--agent` dan menjawab sesuai persona dan izinnya.

## Fase 3: Memori (data lama dibawa ke Antigravity)

Sumber yang masih utuh: 96 stories, 77 perintah PO, 380 task (`diff_snapshot`,
`qa_feedback`), 230 temuan QA, 1.686 pesan eksekusi, 1.194 pesan chat/estimasi, dan
riwayat git (396+ commit). `opencode.db` sudah dihapus dan tidak dibutuhkan.

- [ ] Perintah `php artisan memori:ekspor {project}` untuk menyusun ringkasan dari DB dan git:
  - [ ] `docs/memori-ai.md` di repo proyek: fitur yang sudah ada per modul, keputusan PO,
        story yang diterima atau ditolak beserta alasannya.
  - [ ] `.agents/rules/pelajaran-qa.md`: pola kesalahan berulang dari temuan QA
        (contoh: enum `PrioritasUsulan` jangan dipakai sebagai kunci array).
  - [ ] `.agents/skills/konvensi-proyek/SKILL.md`: struktur folder, komponen UI, cara tes.
- [ ] Batasi ukuran sesuai doc: maksimal 24 KB per file rule, sekitar 20k token total rules always_on.
- [ ] Salin isi `~/.hermes/memories/MEMORY.md` dan `USER.md` yang masih relevan.
- [ ] Uji: agen QA ditanya "kesalahan apa yang pernah terjadi di halaman detail usulan?"
      dan bisa menjawab dari memori.

## Fase 4: Pindahkan setiap pekerjaan AI ke Antigravity

- [ ] **Eksekusi task BE/FE:** `AntigravityRunner` menggantikan `OpenCodeRunner` dan
      `HermesTaskRunner`. cwd = worktree task, `--mode accept-edits`, `--print-timeout`
      sesuai batas job. Commit, diff, dan gate tetap dikerjakan ScrumSpace.
- [ ] **QA batch:** `BatchQa` / `ReviewExecutionTaskJob` memanggil `--agent qa-engineer` + `--json-schema`.
- [ ] **Breakdown SM:** `BreakdownExecutionJob` + `--json-schema`. Validator yang ada tetap dipakai.
- [ ] **Estimasi Fibonacci:** `StoryEstimationService` memanggil FE/BE/QA lewat `--agent`.
- [ ] **Chat SM:** `ChatController` / `UnderstandChatCommandJob`. Pakai `--conversation <id>`
      supaya percakapan berlanjut. Tampilkan indikator loading karena tiap pesan butuh beberapa detik.
- [ ] **Dialog temuan (FindingDialogue):** dev menjawab temuan, SM menengahi.
- [ ] Mode bayangan: command `CompareExecutionDrivers` membandingkan hasil lama vs `agy` pada 3 story uji.

## Fase 5: Halaman AI Model (login Google di website)

Mengganti halaman `ai-providers` (sesuai mockup yang sudah disetujui).

- [ ] Kartu status: akun terhubung, versi `agy`, model yang tersedia (`agy models`).
- [ ] Login dari web:
  - [ ] Tombol "Hubungkan akun Google": server menjalankan login `agy` di PTY sebagai
        `sidik`, memilih "Google OAuth", menangkap URL, lalu menampilkannya sebagai tombol.
  - [ ] Kolom "Tempel kode": kode diteruskan ke proses yang sama, proses ditutup, status dicek ulang.
  - [ ] Batas waktu sesi login (mis. 5 menit), satu sesi login aktif saja, kode tidak pernah disimpan atau dicatat di log.
  - [ ] Tombol "Keluar" (logout) dan "Uji koneksi" (prompt kecil + model murah).
- [ ] Pilihan jenis akun: Akun Google / Google Cloud project / Gemini API key.
- [ ] Model + effort per peran (SM, BE, FE, QA), dengan model cadangan.
- [ ] Kuota: cari cara membaca `/usage` atau `/credits`. Kalau tidak bisa di mode headless,
      tampilkan pemakaian token dari `ai_usage_records` saja.
- [ ] Hapus UI lama: OAuth CLIProxy, API key CLIProxy, kuota Antigravity internal (`AntigravityQuota`).

## Fase 6: Fitur slash command yang diadopsi

Dari https://antigravity.google/docs/slash-commands/. Slash command dapat dipakai di mode
print (ada flag `--disable-slash-commands`); perlu diuji per command.

- [ ] **`/grill-me` → refinement SM:** SM menanyai PO satu per satu dengan rekomendasi
      jawaban sebelum story disusun. Cocok untuk tombol "Refinement" di chat SM.
- [ ] **`/plan` → rencana breakdown:** SM membuat artefak rencana yang bisa ditinjau PO
      sebelum izin eksekusi. Melengkapi langkah "Izin PO".
- [ ] **`/learn` → memori otomatis:** setelah PO memutuskan story (Berhasil/Gagal), SM
      menyaring koreksi menjadi usulan rules/skills (`learning_proposal.md`). PO menyetujui
      di website sebelum ditulis. Ini mekanisme memori jangka panjang.
- [ ] **`/btw` → tanya cepat:** PO bertanya ke SM saat run berjalan tanpa mengganggu eksekusi.
- [ ] **`/antigravity-guide` → bantuan:** tombol "Tanya bantuan" di halaman AI Model.
- [ ] **`/goal`: jangan dipakai di eksekusi otomatis**, karena tidak ada batas iterasi.
      Batas tetap dari `--print-timeout` dan watchdog ScrumSpace.
- [ ] **Tidak diadopsi sekarang:** `/swarm` dan `/run-workflow` (hanya Pro/Ultra, boros
      kuota), `/teamwork` (paket berbayar, tumpang tindih dengan orkestrasi ScrumSpace),
      `/schedule` dan `/automation` (sudah ada scheduler Laravel), `/browser` (chromium sudah
      dihapus; pertimbangkan nanti untuk QA UI di demo).

## Fase 7: Uji dan rilis

- [ ] `vendor/bin/pint --dirty` dan `php artisan test` hijau.
- [ ] Uji ujung ke ujung di VPS dengan 1 story kecil: estimasi → breakdown → izin PO →
      BE/FE → QA → demo → Terima.
- [ ] Uji gagal: belum login, kuota habis, timeout, dan QA menolak (harus masuk NEEDS_PO dengan alasan jelas).
- [ ] Merge ke `main` dan deploy lewat webhook. Cek `https://scrum-team.artitechlabs.com/up` dan `systemctl is-active scrumspace-queue`.

## Fase 8: Bersih-bersih (setelah 1 minggu stabil)

- [ ] Hapus kode lama: `OpenCodeRunner`, `HermesTaskRunner`, `HermesClient`, `KanbanClient`,
      `CliProxyApiClient`, `AntigravityQuota`, `KanbanExecutionPollJob`, command terkait, dan env `OPENCODE_*` / `CLIPROXY_*` / `HERMES_*`.
- [ ] Matikan dan hapus layanan: `cliproxy.service` (dan `/var/lib/cliproxy`), `/usr/bin/opencode`,
      `storage/app/private/.opencode-home`, gateway dan profil Hermes (`~/.hermes`).
- [ ] Tutup port 8317 (CLIProxy).
- [ ] Perbarui `README.md` dan `docs/alur-eksekusi.md`.
- [ ] Catat ruang disk yang kembali (perkiraan lebih dari 3 GB).

## Rollback

Fase 8 sudah dijalankan, jadi jalur lama tidak bisa diaktifkan lagi lewat env. Bila Antigravity
bermasalah: cek `php artisan scrumspace:doctor`, hubungkan ulang akun di halaman AI Model, atau
ganti model. Mengembalikan Hermes/OpenCode/CLIProxy berarti memasang ulang ketiganya dan
me-revert `459771e`; konfigurasi dan memorinya ada di `~/backup-migrasi-antigravity-20261119/`.
