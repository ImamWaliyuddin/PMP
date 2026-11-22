<?php

namespace App\Services;

/**
 * Pengganti ekstraktor untuk demo tanpa API key.
 * Jika nama file cocok dengan PDF dummy (DUMMY-001.pdf ...) isinya diambil dari database/sample/labels.json
 * (ground truth generator dummy) lengkap dengan simulasi APR yang tidak terbaca (apr_unreadable) dan APR yang salah
 * tulis (apr_nama), sehingga logika fallback ke Dukcapil bisa dicoba. Selain itu dibuat data acak.
 */
class MockExtractor implements ApExtractor
{
    public function extract(string $pdfBytes, string $filename): array
    {
        usleep(400000); // simulasi waktu baca

        $base = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
        $labels = [];
        $path = database_path('sample/labels.json');
        if (is_file($path)) {
            foreach (json_decode(file_get_contents($path), true) ?: [] as $r) $labels[$r['id']] = $r;
        }
        $r = $labels[$base] ?? null;

        if ($r) {
            [$rt, $rw] = array_pad(explode('/', $r['rt_rw']), 2, '');
            $raw = [
                'nama_lengkap' => $r['apr_nama'] ?? $r['nama'], 'tempat_lahir' => $r['tempat_lahir'],
                'tanggal_lahir' => $r['tgl_lahir'], 'nama_ibu_kandung' => $r['ibu_kandung'], 'jenis_kelamin' => $r['gender'],
                'nik' => $r['nik'], 'telepon_selular' => $r['hp'], 'email' => $r['email'],
                'status_perkawinan' => $r['status'], 'alamat_jalan' => $r['alamat'], 'rt' => $rt, 'rw' => $rw,
                'kota_kabupaten' => $r['kota'], 'kode_pos' => $r['kodepos'], 'pekerjaan_sekarang' => $r['pekerjaan'],
                'nama_perusahaan' => $r['perusahaan'], 'jabatan' => $r['jabatan'], 'tahun_mulai' => $r['tahun_mulai'],
                'penghasilan_per_bulan' => $r['penghasilan'],
                'dukcapil' => [
                    'ada' => true, 'nik' => $r['nik'], 'nama_lengkap' => $r['nama'], 'tempat_lahir' => $r['tempat_lahir'],
                    'tanggal_lahir' => $r['tgl_lahir'], 'jenis_kelamin' => $r['gender'] === 'Pria' ? 'LAKI-LAKI' : 'PEREMPUAN',
                    'nama_ibu_kandung' => $r['ibu_kandung'],
                ],
            ];
            $uncertain = [];
            foreach ((array)($r['apr_unreadable'] ?? []) as $u) {
                // apr_unreadable bisa berupa list (JSON) atau teks "a;b"
                foreach (is_array($u) ? $u : explode(';', (string)$u) as $key) {
                    $map = ['nama' => ['nama_lengkap'], 'ttl' => ['tempat_lahir', 'tanggal_lahir'], 'nik' => ['nik'], 'ibu' => ['nama_ibu_kandung']];
                    foreach ($map[$key] ?? [] as $f) {
                        $raw[$f] = '';
                        $uncertain[] = $f;
                    }
                }
            }
            return ['raw' => $raw, 'uncertain' => $uncertain];
        }

        $n = (int)preg_replace('/\D/', '', $base) ?: random_int(1, 9999);
        mt_srand($n);
        $nik = '99' . str_pad((string)mt_rand(0, 99999999999999), 14, '0', STR_PAD_LEFT);
        $raw = [
            'nama_lengkap' => 'CONTOH NASABAH ' . chr(65 + $n % 26), 'tempat_lahir' => 'BANDUNG', 'tanggal_lahir' => '1995-06-15',
            'nama_ibu_kandung' => 'SITI AMINAH', 'jenis_kelamin' => $n % 2 ? 'Pria' : 'Wanita', 'nik' => $nik,
            'telepon_selular' => '0800' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT), 'email' => 'contoh' . $n . '@example.com',
            'status_perkawinan' => 'Lajang', 'alamat_jalan' => 'Jl. Contoh No.' . ($n % 90 + 1), 'rt' => '4', 'rw' => '9',
            'kota_kabupaten' => 'Kota Bandung', 'kode_pos' => '40111', 'pekerjaan_sekarang' => 'Swasta', 'jabatan' => 'Staff',
            'tahun_mulai' => '2021', 'penghasilan_per_bulan' => '5.000.000',
            'dukcapil' => ['ada' => true, 'nik' => $nik, 'nama_lengkap' => 'CONTOH NASABAH ' . chr(65 + $n % 26), 'tempat_lahir' => 'BANDUNG',
                'tanggal_lahir' => '1995-06-15', 'jenis_kelamin' => $n % 2 ? 'LAKI-LAKI' : 'PEREMPUAN', 'nama_ibu_kandung' => 'SITI AMINAH'],
        ];
        $uncertain = [];
        if ($n % 4 === 0) { // demo: NIK APR kurang satu digit
            $raw['nik'] = substr($nik, 0, 15);
            $uncertain[] = 'nik';
        }
        return ['raw' => $raw, 'uncertain' => $uncertain];
    }
}
