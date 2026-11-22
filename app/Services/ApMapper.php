<?php

namespace App\Services;

/**
 * Memetakan hasil baca APR (field mentah dari ekstraktor) ke kolom BAOWNITEMPLATE.
 */
class ApMapper
{
    private const MONTHS = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7,
        'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'ags' => 8,
        'sep' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12,
    ];

    /**
     * @param array<string,mixed> $raw        field mentah (APR + blok 'dukcapil')
     * @param array<int,string>   $uncertainCols kolom template yang tulisannya diragukan pembaca
     * @return array<string,mixed> nilai kolom + '_warnings' + '_suggest'
     */
    public static function toRow(array $raw, array $uncertainCols = []): array
    {
        $g = fn(string $k) => trim((string)($raw[$k] ?? ''));
        $warn = [];
        $row = [];

        $row['nama'] = $g('nama_lengkap');
        $row['gelarsbl'] = $g('gelar_depan');
        $row['gelarsdh'] = $g('gelar_belakang');

        $gender = mb_strtolower($g('jenis_kelamin'));
        $row['kelamin'] = str_contains($gender, 'wanita') || str_contains($gender, 'female') || $gender === 'f' || $gender === 'p' ? 'F'
            : (str_contains($gender, 'pria') || str_contains($gender, 'male') || $gender === 'm' || $gender === 'l' ? 'M' : '');

        $row['tgl_lhr'] = self::dateDdmmyyyy($g('tanggal_lahir'));
        $row['kota_lhr'] = $g('tempat_lahir');
        $row['no_ktp'] = $g('nik');
        $row['kota_ktp'] = $g('kota_kabupaten') ?: $g('kota_ktp');
        $row['npwp'] = $g('npwp');
        $row['ibu_kandung'] = $g('nama_ibu_kandung');

        // status kawin
        $s = mb_strtolower($g('status_perkawinan'));
        if (str_contains($s, 'belum') || str_contains($s, 'lajang') || str_contains($s, 'single')) $row['sts_kawin'] = 'B';
        elseif (str_contains($s, 'janda')) $row['sts_kawin'] = 'C';
        elseif (str_contains($s, 'duda') && str_contains($s, 'janda') === false) $row['sts_kawin'] = 'D';
        elseif (str_contains($s, 'menikah') || str_contains($s, 'kawin') || str_contains($s, 'married')) $row['sts_kawin'] = 'A';
        else $row['sts_kawin'] = '';
        if ($s !== '' && str_contains($s, 'duda') && str_contains($s, 'janda')) {
            // form APR memakai satu kotak "Duda / Janda": tentukan dari jenis kelamin
            $row['sts_kawin'] = $row['kelamin'] === 'F' ? 'C' : 'D';
            $warn['sts_kawin'] = 'Dipilih dari jenis kelamin (kotak Duda/Janda), cek ulang';
        }

        // alamat
        $row['alamat1'] = $g('alamat_jalan');
        $rt = preg_replace('/\D/', '', $g('rt'));
        $rw = preg_replace('/\D/', '', $g('rw'));
        $row['alamat2'] = ($rt !== '' && $rw !== '') ? sprintf('RT%03d RW%03d', (int)$rt, (int)$rw) : '';
        $row['kodepos'] = $g('kode_pos');

        // kontak
        $row['tlp_rmh'] = $g('telepon_rumah');
        $row['no_hp'] = $g('telepon_selular');
        $row['email'] = $g('email');

        // pekerjaan
        $row['pekerjaan'] = self::mapPekerjaan($g('pekerjaan_sekarang'));
        if ($row['pekerjaan'] === 'LAIN' && $g('pekerjaan_sekarang') !== '') $warn['pekerjaan'] = 'Pilihan di form tidak dikenali, diset LAIN';
        $row['jabatan'] = self::mapJabatan($g('jabatan'));
        if ($row['jabatan'] === '' && $g('jabatan') !== '') $warn['jabatan'] = 'Jabatan di form: "' . $g('jabatan') . '" belum cocok dengan kode';

        // tanggal mulai: form APR hanya memuat tahun mulai bekerja
        $tm = preg_replace('/\D/', '', $g('tahun_mulai'));
        if (strlen($tm) === 4) {
            $row['tgl_mulai'] = '0101' . substr($tm, 2, 2);
            $warn['tgl_mulai'] = 'Form hanya memuat tahun; tanggal/bulan diasumsikan 01/01';
        } elseif (strlen($tm) === 8) {
            $row['tgl_mulai'] = substr($tm, 0, 4) . substr($tm, 6, 2);
        } else {
            $row['tgl_mulai'] = $tm;
        }

        $row['gaji'] = $g('penghasilan_per_bulan');
        $row['pen_lain'] = $g('penghasilan_lain') ?: '0';

        $suggest = self::mergeDukcapil($row, (array)($raw['dukcapil'] ?? []), $uncertainCols, $warn);

        $row['_warnings'] = $warn;
        $row['_suggest'] = $suggest;
        return $row;
    }

    /**
     * Cetakan Dukcapil (halaman lain di PDF) dipakai sebagai pembanding data identitas.
     * - APR kosong / tidak terbaca / NIK tidak 16 digit  -> isi dari Dukcapil
     * - APR terbaca tetapi beda dengan Dukcapil          -> nilai APR dipertahankan, ditandai kuning + saran
     *
     * @return array<string,string> saran nilai per kolom (untuk tombol "Pakai Dukcapil")
     */
    private static function mergeDukcapil(array &$row, array $dk, array $uncertainCols, array &$warn): array
    {
        if (!$dk) return [];
        $g = fn(string $k) => trim((string)($dk[$k] ?? ''));

        $gender = mb_strtolower($g('jenis_kelamin'));
        $dkGender = str_contains($gender, 'perempuan') || str_contains($gender, 'wanita') ? 'F'
            : (str_contains($gender, 'laki') || str_contains($gender, 'pria') ? 'M' : '');

        $dkVals = [
            'nama' => mb_strtoupper($g('nama_lengkap')),
            'no_ktp' => preg_replace('/\D/', '', $g('nik')),
            'kota_lhr' => mb_strtoupper($g('tempat_lahir')),
            'tgl_lhr' => self::dateDdmmyyyy($g('tanggal_lahir')),
            'ibu_kandung' => mb_strtoupper($g('nama_ibu_kandung')),
            'kelamin' => $dkGender,
        ];
        $norm = fn(string $s) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($s));

        $suggest = [];
        foreach ($dkVals as $col => $val) {
            if ($val === '') continue;
            $cur = trim((string)($row[$col] ?? ''));

            $unreadable = $cur === '' || in_array($col, $uncertainCols, true);
            $badNik = $col === 'no_ktp' && strlen(preg_replace('/\D/', '', $cur)) !== 16 && strlen($val) === 16;

            if ($unreadable || $badNik) {
                $row[$col] = $val;
                $warn[$col] = $cur === '' || $unreadable
                    ? 'Tulisan di APR kosong/tidak terbaca, diisi dari Dukcapil'
                    : 'NIK di APR tidak valid, diganti dari Dukcapil';
            } elseif ($norm($cur) !== $norm($val)) {
                $warn[$col] = 'Beda dengan Dukcapil: ' . $val;
                $suggest[$col] = $val;
            }
        }
        return $suggest;
    }

    private static function dateDdmmyyyy(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%02d%02d%04d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{1,2})[\/\-. ](\d{1,2})[\/\-. ](\d{4})$/', $s, $m)) {
            return sprintf('%02d%02d%04d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $s, $m) && isset(self::MONTHS[mb_strtolower($m[2])])) {
            return sprintf('%02d%02d%04d', $m[1], self::MONTHS[mb_strtolower($m[2])], $m[3]);
        }
        return $s;
    }

    private static function mapPekerjaan(string $s): string
    {
        $s = mb_strtolower($s);
        if ($s === '') return '';
        $map = [
            'wiraswasta' => 'WSW', 'entrepreneur' => 'WSW', 'swasta' => 'PSW', 'private' => 'PSW',
            'asn' => 'PNS', 'pns' => 'PNS', 'civil servant' => 'PNS', 'bumn' => 'PBUM', 'bumd' => 'PBUM',
            'polri' => 'POL', 'polisi' => 'POL', 'tni' => 'MIL', 'army' => 'MIL',
            'pelajar' => 'MHS', 'mahasiswa' => 'MHS', 'student' => 'MHS', 'rumah tangga' => 'IRT', 'housewife' => 'IRT',
            'pejabat negara' => 'PNG', 'pensiun' => 'PENS', 'dokter' => 'DKTR', 'guru' => 'GR', 'dosen' => 'GR',
        ];
        // urutan penting: "wiraswasta" sebelum "swasta"
        foreach ($map as $needle => $code) {
            if (str_contains($s, $needle)) return $code;
        }
        return 'LAIN';
    }

    private static function mapJabatan(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') return '';
        if (preg_match('/^\d{1,2}$/', $s)) return str_pad($s, 2, '0', STR_PAD_LEFT);
        $rules = [
            '/komisaris|pemilik|owner/' => '01', '/direktur utama|dirut|ceo|presiden direktur/' => '02',
            '/wakil direktur|wadir/' => '04', '/direktur|director/' => '03', '/general manager|^gm|kepala biro/' => '05',
            '/assistant manager|asisten manajer|asisten manager|asst|kepala seksi|supervisor/' => '07',
            '/manager|manajer|kepala bagian|kabag|kepala divisi/' => '06',
            '/staff|staf|karyawan|officer|analyst|analis|engineer|teknisi/' => '08',
            '/pelaksana|operator/' => '09', '/guru|dosen/' => '24', '/dokter/' => '22', '/notaris|pengacara/' => '23',
        ];
        foreach ($rules as $re => $code) {
            if (preg_match($re, $s)) return $code;
        }
        return '';
    }
}
