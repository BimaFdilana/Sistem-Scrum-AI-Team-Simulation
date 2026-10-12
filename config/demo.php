<?php

return [
    // Rentang port untuk demo project sementara (satu port per project).
    // Pastikan rentang ini dipublish di docker-compose / firewall VPS.
    'port_from' => (int) env('DEMO_PORT_FROM', 9110),
    'port_to' => (int) env('DEMO_PORT_TO', 9130),

    // Demo berhenti otomatis setelah sekian menit (0 = tanpa batas).
    'ttl_minutes' => (int) env('DEMO_TTL_MINUTES', 60),
];
