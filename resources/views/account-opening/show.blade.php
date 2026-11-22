@extends('layouts.pmp')
@section('title', 'Hasil Request')
@section('crumb', 'Account Opening > Hasil Request')
@section('content')
<div class="head">
    <h1 class="title" style="margin:0">Hasil Request</h1>
    <div class="actions">
        <a class="btn" href="{{ route('ao.index') }}">Kembali</a>
        <a class="btn primary" href="{{ route('ao.download', $req) }}">&#8681; Download BAOWNITEMPLATE.xlsx</a>
    </div>
</div>

<div class="alert ok">Request berhasil dibuat. File Excel di bawah sudah berformat template bulk PMP (23 kolom) dan bisa langsung diimpor.</div>

<div class="card">
    <div class="grid" style="grid-template-columns:repeat(4,1fr)">
        <div class="f"><label>Request ID</label><span class="mono">{{ $req->request_id }}</span></div>
        <div class="f"><label>Tanggal</label><span>{{ $req->created_at->format('Y-m-d H:i') }}</span></div>
        <div class="f"><label>Group Code</label><span>{{ $req->group_code }}</span></div>
        <div class="f"><label>Branch</label><span>{{ $req->branch }}</span></div>
        <div class="f"><label>Mitra</label><span>{{ $req->mitra_id }} - {{ $req->mitra['name'] ?? '' }}</span></div>
        <div class="f"><label>Sektor Industri</label><span>{{ $req->mitra['sektor'] ?? '' }}</span></div>
        <div class="f"><label>Consent (pesan, suara, SMS, email)</label><span class="mono">{{ $req->consent }}</span></div>
        <div class="f"><label>Jumlah Data</label><span>{{ $req->jumlah }}</span></div>
    </div>
</div>

<div class="card">
    <div class="scroll">
        <table class="t">
            <thead><tr><th>#</th>@foreach($columns as $c)<th>{{ $labels[$c] }}</th>@endforeach<th>APR</th></tr></thead>
            <tbody>
            @foreach($req->items as $i => $it)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    @foreach($columns as $c)<td class="{{ in_array($c, ['no_ktp','npwp','no_hp','tgl_lhr','tgl_mulai']) ? 'mono' : '' }}">{{ $it->data[$c] ?? '' }}</td>@endforeach
                    <td>@if($it->file_path)<a href="{{ route('ao.item.pdf', $it) }}" target="_blank">{{ $it->original_name ?: 'PDF' }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
