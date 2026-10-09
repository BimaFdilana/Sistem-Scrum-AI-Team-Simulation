# ScrumSpace — Protokol konsensus Fibonacci

## Kontrak tiga putaran

- **Putaran 1 — independen:** Backend, Frontend, dan QA memilih satu poin keseluruhan story dari `1, 2, 3, 5, 8, 13` dan memberi alasan singkat.
- **Putaran 2 — debat:** setiap spesialis membaca pesan nyata putaran 1 yang lengkap, lalu mengirim JSON ketat `estimate_points`, `rationale`, `response`, dan boolean `explicit_agree`. Pesan peer disimpan sebagai bukti nyata.
- **Putaran 3 — konfirmasi final:** layanan membangun satu ringkasan deterministik dari evidence putaran 1/2 dan kandidat poin yang benar-benar muncul. Ringkasan, kandidat, snapshot story, dan instruksi final yang sama dikirim identik ke ketiga spesialis. Layanan tidak memilih, merata-ratakan, atau memakai Scrum Master untuk memilih poin.

Format putaran 3 adalah tepat:

```json
{"estimate_points":5,"explicit_agree":true,"rationale":"alasan singkat"}
```

`explicit_agree` tidak pernah diinferensikan dari teks. Konsensus hanya sah bila ketiga respons putaran 3 memilih poin Fibonacci identik, ketiganya `explicit_agree: true`, dan masing-masing telah menerima pertukaran putaran 2. Jika tidak, run berakhir `NO_CONSENSUS` setelah tiga putaran; tidak ada fallback manual PO, Scrum Master, maksimum, atau rata-rata.

## Evidence dan batas tampilan

Pesan asli setiap respons serta salinan peer tetap disimpan persis di database. Untuk prompt berikutnya, layanan merapikan whitespace dan memotong tiap excerpt secara aman; ringkasan final juga memiliki batas total. Batas prompt tidak mengubah evidence database atau transkrip laporan. UI hanya menampilkan peran, putaran, poin, status agreement, waktu, serta rationale/response singkat—bukan prompt, JSON mentah, atau chain-of-thought.

## Status dan pelaporan

Run yang mencapai konsensus masuk `REPORT_PENDING`. Scrum Master hanya membuat laporan berdasarkan evidence dan tidak mengubah poin. Story point dan laporan ditulis atomik; kegagalan laporan mempertahankan evidence untuk retry tanpa mengulang spesialis. Run tanpa konsensus tidak mengubah story.

Halaman Diskusi Tim requester-scoped menampilkan run milik requester dalam bubble chat ringkas, header putaran `x/3`, banner final atau `NO_CONSENSUS`, dan bagian `Execution & QA` kosong sampai executor terisolasi serta bukti QA tersedia. Tidak ada migrasi aktif atau perubahan pada run historis yang gagal.
