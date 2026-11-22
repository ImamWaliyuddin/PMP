<?php

return [
    // claude | mock
    'extractor' => env('PMP_EXTRACTOR', 'mock'),

    'branch_code' => env('PMP_BRANCH_CODE', '12703'),

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-sonnet-5-5'),
        'endpoint' => env('CLAUDE_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'timeout' => env('CLAUDE_TIMEOUT', 180),
    ],

    // Data mitra FIKTIF untuk prototype. Ganti/tambah sesuai kebutuhan.
    'mitras' => [
        ['id' => '1270120', 'name' => 'PT Contoh Maju Bersama', 'pipeline_id' => 'PL-2026-0001', 'kode_produk' => 'TPY01', 'biaya_tabungan' => '0', 'status_kerjasama' => 'Aktif', 'status_benefit' => 'Benefit Payroll Reguler', 'sektor' => 'Perdagangan'],
        ['id' => '1270605', 'name' => 'PT Nusantara Fiktif Abadi', 'pipeline_id' => 'PL-2026-0002', 'kode_produk' => 'TPY02', 'biaya_tabungan' => '5000', 'status_kerjasama' => 'Aktif', 'status_benefit' => 'Benefit Payroll Premium', 'sektor' => 'Manufaktur'],
        ['id' => '1270733', 'name' => 'CV Dummy Sejahtera', 'pipeline_id' => 'PL-2026-0003', 'kode_produk' => 'TPY01', 'biaya_tabungan' => '0', 'status_kerjasama' => 'Aktif', 'status_benefit' => 'Benefit Payroll Reguler', 'sektor' => 'Jasa Konsultasi'],
    ],

    'sektor_industri' => ['Perdagangan', 'Manufaktur', 'Jasa Konsultasi', 'Teknologi Informasi', 'Konstruksi', 'Logistik', 'Makanan & Minuman', 'Energi', 'Tekstil', 'Kesehatan', 'Pendidikan'],
];
