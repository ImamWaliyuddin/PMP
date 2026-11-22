# PMP Autofill (prototype)

Prototype fitur **Autofill APR** untuk menu *Account Opening > Manual Bulk* pada Payroll Management Platform.

Alur:
1. **Add Request > Manual Bulk**.
2. Upload banyak PDF scan APR (satu PDF = satu nasabah, maks 5MB).
3. Sistem membaca tulisan tangan dan mengisi tabel **Informasi Data Diri + Informasi Pekerjaan**
   (kolom mengikuti `BAOWNITEMPLATE.xlsx`). Sel yang melanggar aturan sheet *Petunjuk Pengisian* ditandai merah,
   sel yang diragukan ditandai kuning, semuanya bisa diedit langsung.
   Jika PDF berisi halaman **Dukcapil** (cetakan komputer) setelah APR, halaman itu dipakai sebagai pembanding:
   - tulisan APR kosong/tidak terbaca (nama, tempat-tanggal lahir, NIK, ibu kandung, jenis kelamin) -> **diisi dari Dukcapil**, sel kuning;
   - NIK di APR bukan 16 digit -> diganti NIK Dukcapil;
   - APR terbaca tetapi **beda** dengan Dukcapil -> nilai APR dipertahankan, sel kuning + tombol **Pakai Dukcapil**.
4. **Informasi Mitra** dan **Informasi Lainnya (consent)** diisi **sekali** untuk semua nasabah.
5. **Submit**: request tercatat di list Account Opening dan bisa mengunduh `BAOWNITEMPLATE_<RequestID>.xlsx`
   (23 kolom, sudah memuat CONSENT, siap diimpor ke PMP).

Stack: Laravel 11, Blade, Alpine.js (sudah di `public/js`, tanpa build step), SQLite.

## Setup lokal (Laragon / terminal)

Butuh PHP 8.2+ (ext: zip, sqlite3/pdo_sqlite, mbstring, curl) dan Composer.

```bash
composer install
copy .env.example .env        # Linux/Mac: cp .env.example .env
php artisan key:generate
php artisan migrate           # jawab "yes" bila ditanya membuat database.sqlite
php artisan serve             # buka http://127.0.0.1:8000
```

Tidak perlu `npm install`.

## Mode ekstraksi (`.env`)

| Mode | Setting | Keterangan |
|---|---|---|
| Mock (default) | `PMP_EXTRACTOR=mock` | Tanpa API key. Isi diambil dari contoh (`database/sample/labels.json` bila nama file `DUMMY-001.pdf` dst, selain itu data acak), termasuk simulasi APR tidak terbaca / salah tulis supaya fallback Dukcapil bisa dicoba. |
| Claude | `PMP_EXTRACTOR=claude`<br>`ANTHROPIC_API_KEY=sk-ant-...`<br>`CLAUDE_MODEL=claude-sonnet-5-5` | PDF dikirim ke Claude API (vision) dan dibaca tulisan tangannya. |

Catatan data: mode `claude` mengirim isi PDF ke API eksternal. Pakai data dummy untuk prototype; untuk data nasabah
asli perlu persetujuan keamanan informasi dan opsi model yang berjalan di lingkungan bank.

Jika upload ditolak, naikkan `upload_max_filesize` dan `post_max_size` di `php.ini` (minimal 6M).

## Peta kode

| File | Fungsi |
|---|---|
| `app/Support/Pmp.php` | Definisi 23 kolom, kode PEKERJAAN/JABATAN/STS_KAWIN, teks Petunjuk Pengisian |
| `app/Services/RowValidator.php` | Semua aturan validasi + normalisasi (satu sumber, dipakai ekstraksi, edit sel, dan submit) |
| `app/Services/ApMapper.php` | Field mentah APR -> kolom template (tanggal, RT/RW, kode pekerjaan, status kawin, dst) + penggabungan dengan Dukcapil (`mergeDukcapil`) |
| `app/Services/ClaudeExtractor.php` | Prompt + panggilan Claude API (ubah `PROMPT` untuk menyetel hasil) |
| `app/Services/MockExtractor.php` | Ekstraktor palsu untuk demo |
| `app/Services/XlsxWriter.php` | Penulis `.xlsx` tanpa dependensi (kolom teks supaya angka 0 di depan tidak hilang) |
| `app/Http/Controllers/AccountOpeningController.php` | Route halaman, extract, validate-row, submit, download |
| `resources/views/account-opening/bulk.blade.php` | Halaman Manual Bulk + logika Alpine |
| `config/pmp.php` | Kode cabang, **data mitra fiktif**, daftar sektor industri |

## Hal yang perlu kamu sesuaikan

- **Daftar jabatan**: halaman referensi terpotong di kode 24, tambahkan kode lainnya di `Pmp::JABATAN`.
- **Mitra & sektor industri**: data di `config/pmp.php` fiktif.
- **TGL_MULAI**: APR hanya memuat tahun mulai kerja, jadi diisi `0101YY` dan ditandai kuning.
- **NPWP**: template mewajibkan NPWP untuk gaji di atas Rp 4.500.000; bila APR kosong, baris ditandai merah.
- **NO_HP**: otomatis diubah ke awalan `62` (mis. `0813...` jadi `62813...`) sesuai aturan template.
- **Aturan gabung Dukcapil** ada di `ApMapper::mergeDukcapil`; ubah di sana bila ingin Dukcapil selalu menang saat beda.
- Menu selain Account Opening hanya tampilan; halaman Manual Satuan hanya replika visual.
