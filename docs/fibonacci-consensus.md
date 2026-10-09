# ScrumSpace — Diskusi Fibonacci dan model bersama

## Alur yang sudah diterapkan

- PO menekan **Diskusi Fibonacci via Scrum Master** pada User Story di Sprint PLANNING. Story/revisi/aktor/tujuan/Acceptance Criteria menjadi snapshot; proses konsultasi tidak menjalankan tool project.
- Putaran 1: BE, FE, QA mengirim poin keseluruhan story dari `1,2,3,5,8,13` dan alasan independen. Putaran 2–3: tiap profil menerima pesan nyata dari putaran lengkap sebelumnya, menanggapi argumen lain dan menyatakan `explicit_agree` true/false. Persetujuan akhir hanya bila ketiganya menerima poin identik setelah pertukaran; usulan awal identik sendiri tidak cukup. Tidak ada maksimum/rata-rata/keputusan sepihak SM.
- Paling banyak tiga putaran tim; tanpa kesepakatan status `NO_CONSENSUS` dan tidak ada poin final. Kategori ukuran: 1–2 Ringan, 3–5 Sedang, 8–13 Berat.
- Konsensus menunggu laporan SM ke chat PO. Poin story baru tersimpan bersama laporan yang berhasil dicatat dalam satu transaksi. Jika laporan gagal: `REPORT_PENDING`, lalu tombol retry mengulang **hanya laporan**, bukan usulan spesialis.
- Tombol **Lihat Diskusi Tim** pada setiap story menampilkan pertukaran dan status dari run yang diminta pengguna tersebut, baca-saja. Belum ada pesan eksekusi dan revisi QA karena executor task/project tetap nonaktif. Tombol **Eksekusi User Story** sengaja disabled walaupun sudah ada poin sampai worktree/sandbox/izin/QA siap.
- RUNNING yang terhenti lebih dari 30 menit dapat dihentikan oleh peminta run; riwayat tidak dihapus. Index parsial SQLite mencegah dua run aktif pada story/revisi sama. Status Sprint dan revisi story diperiksa lagi saat model merespons dan sebelum laporan/poin final disimpan.
- Pemilihan model di menu Agent tetap satu field. **Model yang sama kini diperbolehkan pada beberapa profil**, dengan validasi katalog per profil dan audit versi tetap berlaku; model PO yang sudah dipilih tidak diubah otomatis.

## Migrasi dan preservasi

Tiga migrasi ditargetkan: tabel estimasi baru `2026_10_08_000002`, hapus batas UNIQUE `active_model` `2026_10_09_000001`, serta forward upgrade kompatibilitas `2026_10_09_000002`. Upgrade terakhir no-op untuk tabel baru; jika tabel estimasi lama sudah ada, ia menambah kolom/tabel/index yang belum ada. Run lama tanpa bukti konsensus eksplisit diberi status `LEGACY_UNVERIFIED`, tanpa menghapus record, ringkasan, atau catatan agent; poin lama tidak ditampilkan sebagai hasil kesepakatan baru. Untuk downgrade/rollback, gunakan backup tervalidasi dan prosedur restore yang ditinjau: `down()` forward upgrade sengaja tidak menghapus transkrip atau kolom karena berisiko kehilangan bukti.

Rehearsal pada salinan SQLite aktif menjaga 20 tabel lama, 2 story, 4 konfigurasi model dan 4 audit; foreign-key check dan integrity check bersih. Ketiga migrasi diterapkan ke database aktif setelah izin PO. Sesudahnya, 116 test SQLite terisolasi lulus dan render backend aktif menunjukkan tombol diskusi per story, link baca-saja, tanpa pemilihan poin manual; endpoint `/up` aplikasi dan tunnel HTTP 200.

## Batas yang belum diklaim

Pengujian estimasi memakai stub/fake respons agent untuk membuktikan state dan pesan; **belum ada run diskusi Fibonacci live** atas User Story PO. Browser login end-to-end juga belum tersedia pada mesin pengujian ini. Model/provider tersimpan pernah diverifikasi dengan empat konsultasi minimal yang terpisah, bukan dengan run estimasi 3 putaran. Keberhasilan diskusi live, waktu response, kuota provider, dan kepatuhan semantik SOUL/skill tidak boleh disimpulkan dari test fake.

Kelanjutan: worker async untuk durasi konsultasi panjang, pelaporan NO_CONSENSUS ke chat PO, executor project terisolasi dengan izin tindakan, QA rework maksimal dua siklus, acceptance PO, preview dan ZIP; semuanya belum diaktifkan oleh migrasi ini. ScrumSpace MVP saat ini memakai SQLite dan satu admin manusia; isolasi project multi-user memerlukan model kepemilikan/policy sebelum pendaftaran pengguna ditambah.
