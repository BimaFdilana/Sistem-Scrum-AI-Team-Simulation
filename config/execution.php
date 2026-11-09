<?php

return [
    /*
    | Batas waktu tanpa progres sebelum kartu kanban dianggap menggantung.
    | Worker yang masih bekerja mengirim heartbeat/komentar secara berkala,
    | jadi tidak ada aktivitas selama ini berarti prosesnya macet.
    */
    'stall_minutes' => (int) env('EXECUTION_STALL_MINUTES', 20),

    /*
    | Berapa kali task boleh dipulihkan otomatis (attempt baru) sebelum run
    | dinyatakan TASK_STALLED. Satu kali cukup untuk gangguan sementara;
    | lebih dari itu biasanya berarti masalah yang butuh PO.
    */
    'max_auto_recoveries' => (int) env('EXECUTION_MAX_AUTO_RECOVERIES', 1),

    /*
    | Berapa task boleh berjalan bersamaan dalam satu run. Dibatasi karena
    | tiap task menyalakan satu worker Hermes (±300-500 MB RAM) dan host ini
    | hanya 2 GB. Task yang menyentuh file sama selalu diserialkan scheduler.
    */
    'max_parallel' => (int) env('EXECUTION_MAX_PARALLEL', 2),

    /*
    | Cara agent mengerjakan task:
    |   worktree = salinan terpisah + merge (bisa paralel)
    |   direct   = edit baseline langsung, sekuensial, commit checkpoint
    */
    'mode' => env('EXECUTION_MODE', 'worktree'),

    /*
    | Kapan QA menilai:
    |   per_task = setiap task selesai (perilaku lama)
    |   batch    = sekali di akhir setelah semua task BE/FE selesai
    */
    'qa_mode' => env('EXECUTION_QA_MODE', 'per_task'),

    /* Batas putaran perbaikan setelah review batch — ditegakkan di kode. */
    'qa_max_repair_rounds' => (int) env('EXECUTION_QA_MAX_REPAIR_ROUNDS', 3),

    /*
    | Uji jalan nyata sebelum QA dan saat PO menerima story: halaman yang
    | berubah dibuka dengan salinan database demo; error 500 = temuan.
    */
    'smoke_enabled' => (bool) env('EXECUTION_SMOKE', true),
    'smoke_timeout' => (int) env('EXECUTION_SMOKE_TIMEOUT', 240),
];
