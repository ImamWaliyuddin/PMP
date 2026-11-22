<?php

namespace App\Http\Controllers;

use App\Models\BulkItem;
use App\Models\BulkRequest;
use App\Services\ApExtractor;
use App\Services\ApMapper;
use App\Services\ClaudeExtractor;
use App\Services\RowValidator;
use App\Services\XlsxWriter;
use App\Support\Pmp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AccountOpeningController extends Controller
{
    public function index()
    {
        return view('account-opening.index', ['requests' => BulkRequest::latest()->get()]);
    }

    public function satuan()
    {
        return view('account-opening.satuan', ['mitras' => config('pmp.mitras'), 'sektor' => config('pmp.sektor_industri')]);
    }

    public function reports()
    {
        $bulk = BulkRequest::where('status', 'Success')->count();
        return view('account-opening.reports', ['bulk' => $bulk, 'branch' => config('pmp.branch_code')]);
    }

    public function bulk()
    {
        $config = [
            'columns' => Pmp::TABLE_COLUMNS,
            'labels' => Pmp::LABELS,
            'selects' => [
                'kelamin' => $this->options(Pmp::KELAMIN, true),
                'sts_kawin' => $this->options(Pmp::STS_KAWIN, true),
                'pekerjaan' => $this->options(Pmp::PEKERJAAN),
                'jabatan' => $this->options(Pmp::JABATAN),
            ],
            'mitras' => config('pmp.mitras'),
            'sektor' => config('pmp.sektor_industri'),
            'consent' => collect(Pmp::CONSENT_CHANNELS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'branch' => config('pmp.branch_code'),
            'extractor' => config('pmp.extractor'),
            'urls' => [
                'extract' => route('ao.extract'),
                'validate' => route('ao.validate'),
                'submit' => route('ao.submit'),
                'pdf' => url('/account-opening/bulk/pdf'),
                'index' => route('ao.index'),
            ],
        ];
        return view('account-opening.bulk', compact('config'));
    }

    /** Baca satu PDF scan APR -> satu baris (dipanggil per file dari browser). */
    public function extract(Request $request, ApExtractor $extractor): JsonResponse
    {
        @set_time_limit(240); // pembacaan PDF oleh model bisa 10-60 detik
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:5120']]);

        $file = $request->file('file');
        $bytes = file_get_contents($file->getRealPath());
        $name = $file->getClientOriginalName();

        try {
            $result = $extractor->extract($bytes, $name);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $token = (string)Str::uuid();
        Storage::disk('local')->put("apr/{$token}.pdf", $bytes);

        $uncertainCols = [];
        foreach ($result['uncertain'] as $rawKey) {
            $col = ClaudeExtractor::rawToColumn($rawKey);
            if ($col) $uncertainCols[] = $col;
        }

        $row = ApMapper::toRow($result['raw'], $uncertainCols);
        foreach ($uncertainCols as $col) {
            $row['_warnings'][$col] = $row['_warnings'][$col] ?? 'Tulisan sulit dibaca, cek dengan PDF';
        }
        $v = RowValidator::validate($row);

        return response()->json([
            'token' => $token,
            'filename' => $name,
            'values' => $v['values'],
            'errors' => (object)$v['errors'],
            'warnings' => (object)$v['warnings'],
            'suggest' => (object)($row['_suggest'] ?? []),
        ]);
    }

    /** Validasi ulang satu baris setelah user mengedit sel. */
    public function validateRow(Request $request): JsonResponse
    {
        $v = RowValidator::validate((array)$request->input('values', []));
        return response()->json(['values' => $v['values'], 'errors' => (object)$v['errors'], 'warnings' => (object)$v['warnings']]);
    }

    public function pendingPdf(string $token)
    {
        abort_unless(Str::isUuid($token), 404);
        $path = "apr/{$token}.pdf";
        abort_unless(Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'application/pdf']);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mitra.id' => ['required', 'string'],
            'mitra.sektor' => ['required', 'string'],
            'mitra' => ['required', 'array'],
            'consent' => ['array'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.values' => ['required', 'array'],
            'rows.*.token' => ['nullable', 'string'],
            'rows.*.filename' => ['nullable', 'string'],
        ], [
            'mitra.id.required' => 'Pilih Mitra terlebih dahulu.',
            'mitra.sektor.required' => 'Sektor Industri wajib dipilih.',
            'rows.required' => 'Belum ada data nasabah.',
        ]);

        $rowErrors = [];
        $clean = [];
        foreach ($data['rows'] as $i => $r) {
            $v = RowValidator::validate($r['values']);
            if ($v['errors']) $rowErrors[$i] = $v['errors'];
            $clean[$i] = $v['values'];
        }
        if ($rowErrors) {
            return response()->json(['message' => count($rowErrors) . ' baris masih memiliki kesalahan.', 'row_errors' => $rowErrors], 422);
        }

        $consent = Pmp::consentString($data['consent'] ?? []);
        $mitra = $data['mitra'];

        $req = DB::transaction(function () use ($data, $clean, $consent, $mitra) {
            $requestId = now()->format('YmdHis') . '-' . random_int(1000000, 9999999);
            $req = BulkRequest::create([
                'request_id' => $requestId,
                'group_code' => 'L26K' . strtoupper(Str::random(4)),
                'branch' => config('pmp.branch_code'),
                'mitra_id' => $mitra['id'],
                'mitra' => $mitra,
                'consent' => $consent,
                'jumlah' => count($clean),
                'status' => 'Success',
            ]);
            foreach ($data['rows'] as $i => $r) {
                $path = null;
                $token = $r['token'] ?? null;
                if ($token && Str::isUuid($token) && Storage::disk('local')->exists("apr/{$token}.pdf")) {
                    $path = "apr/{$requestId}/{$token}.pdf";
                    Storage::disk('local')->move("apr/{$token}.pdf", $path);
                }
                $req->items()->create([
                    'data' => $clean[$i],
                    'original_name' => $r['filename'] ?? null,
                    'file_path' => $path,
                ]);
            }
            return $req;
        });

        return response()->json(['redirect' => route('ao.show', $req)]);
    }

    public function show(BulkRequest $bulkRequest)
    {
        return view('account-opening.show', [
            'req' => $bulkRequest->load('items'),
            'columns' => Pmp::TABLE_COLUMNS,
            'labels' => Pmp::LABELS,
        ]);
    }

    public function download(BulkRequest $bulkRequest)
    {
        $rows = $bulkRequest->items->map(fn($it) => $it->data + ['consent' => $bulkRequest->consent])->all();
        return $this->sendXlsx($rows, 'BAOWNITEMPLATE_' . $bulkRequest->request_id . '.xlsx');
    }

    public function template()
    {
        return $this->sendXlsx([], 'BAOWNITEMPLATE.xlsx');
    }

    public function itemPdf(BulkItem $item)
    {
        abort_unless($item->file_path && Storage::disk('local')->exists($item->file_path), 404);
        return response()->file(Storage::disk('local')->path($item->file_path), ['Content-Type' => 'application/pdf']);
    }

    /** Daftar opsi berurutan (hindari JS mengurutkan ulang key numerik). */
    private function options(array $map, bool $labelHasCode = false): array
    {
        $out = [];
        foreach ($map as $code => $label) {
            $out[] = ['code' => (string)$code, 'text' => $labelHasCode ? $label : "$code - $label"];
        }
        return $out;
    }

    private function sendXlsx(array $rows, string $filename)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bao');
        XlsxWriter::baoWniTemplate($rows, $tmp);
        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
