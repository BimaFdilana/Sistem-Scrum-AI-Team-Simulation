# ScrumSpace — Protokol estimasi Fibonacci

Keputusan Bima (2026-10-13): kesepakatan (mufakat) antar-spesialis, Scrum Master
mengumpulkan hasil dan merangkumnya untuk PO; poin Fibonacci murni
`1, 2, 3, 5, 8, 13`; mapping `1,2,3 = Ringan`, `5,8 = Sedang`, `13 = Berat`.

## Kontrak dua putaran

- **Putaran 1 — independen:** Backend, Frontend, dan QA masing-masing memilih satu
  poin keseluruhan story dari `[1,2,3,5,8,13]` (validasi ketat: angka di luar
  daftar ditolak) beserta alasan singkat. Estimasi independen, tanpa melihat
  pendapat spesialis lain.
- **Putaran 2 — konfirmasi final:** setiap spesialis membaca pesan nyata putaran 1
  yang lengkap dari ketiga spesialis, menanggapi/counterargument, lalu
  mempertahankan atau merevisi poinnya. Respons JSON ketat:
  `estimate_points`, `rationale`, `response`, dan boolean `explicit_agree`
  (`true` hanya bila secara tegas menyetujui poinnya sebagai kesepakatan bersama;
  tidak pernah diinferensikan dari teks).

Pesan peer (salinan tiap respons ke dua spesialis lain) disimpan sebagai bukti
nyata di database dan menjadi dasar prompt putaran 2.

## Hasil final: mufakat, lalu Scrum Master merangkum

Setelah putaran 2 selesai dan buktinya lengkap (tiga respons asli + pertukaran
peer putaran 1):

- **Mufakat bulat** — ketiga spesialis memilih poin identik dan ketiganya
  `explicit_agree: true` → poin tersebut menjadi hasil final
  (`consensus_method = 'unanimous'`).
- **Deadlock** — tidak mufakat bulat → Scrum Master menerapkan nilai **MAKSIMUM**
  dari tiga estimasi final sebagai estimasi teraman
  (`consensus_method = 'deadlock_max'`). Ini satu-satunya fallback; tidak ada
  rata-rata atau pilihan manual PO.

Scrum Master kemudian **mengumpulkan hasil tiap spesialis dan merangkumnya**
untuk PO via chat: hasil final + kategori kompleksitas + cara hasil dicapai
(mufakat bulat / deadlock → maksimum) + estimasi final tiap spesialis.
Ringkasan tidak mengarang diskusi, poin, atau persetujuan.

## Status dan pelaporan

Run yang mencapai hasil final masuk `REPORT_PENDING`. Story point dan laporan
ditulis atomik; kegagalan laporan mempertahankan evidence untuk retry tanpa
mengulang spesialis. Status `NO_CONSENSUS` hanya untuk bukti final yang tidak
lengkap (kasus lama); deadlock suara selalu diselesaikan via maksimum.

Halaman Diskusi Tim menampilkan run dalam bubble chat ringkas, header putaran,
banner hasil final (mufakat bulat / deadlock → maksimum), dan bagian
`Execution & QA` kosong sampai executor terisolasi serta bukti QA tersedia.
