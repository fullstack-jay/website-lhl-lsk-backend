<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Pendaftaran Skema Sertifikasi</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px;
        }
        .info-box {
            background-color: #f8fafc;
            border-left: 4px solid #0d9488;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box p {
            margin: 5px 0;
            font-size: 14px;
        }
        .info-box strong {
            color: #334155;
            display: inline-block;
            min-width: 150px;
        }
        .biaya-box {
            background-color: #f0fdfa;
            border: 1px solid #99f6e4;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            text-align: center;
        }
        .biaya-box .nominal {
            font-size: 22px;
            font-weight: 700;
            color: #0f766e;
        }
        table.rekening {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 14px;
        }
        table.rekening th {
            background-color: #f1f5f9;
            text-align: left;
            padding: 10px;
            border: 1px solid #e2e8f0;
        }
        table.rekening td {
            padding: 10px;
            border: 1px solid #e2e8f0;
        }
        .footer {
            padding: 20px 30px;
            background-color: #f8fafc;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Pendaftaran Berhasil</h1>
            <p>Terima kasih telah mendaftar uji kompetensi</p>
        </div>

        <div class="content">
            <p>Kepada Yth. <strong>{{ $asesi->nama }}</strong>,</p>
            <p>Pendaftaran Anda untuk uji kompetensi berhasil diproses.
               Berikut rincian pendaftaran Anda:</p>

            <div class="info-box">
                <p><strong>No. Pendaftaran</strong> {{ $asesi->no_pendaftaran }}</p>
                <p><strong>Kode Skema</strong> {{ $skema->kode_skema }}</p>
                <p><strong>Judul Skema</strong> {{ $skema->judul }}</p>
                <p><strong>Tanggal Daftar</strong> {{ now()->translatedFormat('d F Y') }}</p>
            </div>

            <div class="biaya-box">
                <p style="margin:0 0 5px 0; font-size:13px; color:#475569;">Total Biaya Sertifikasi</p>
                <p class="nominal">{{ $biayaFormatted }}</p>
            </div>

            @if($rekening && count($rekening) > 0)
                <p style="font-size:14px;">Silakan lakukan pembayaran ke salah satu rekening resmi berikut:</p>
                <table class="rekening">
                    <thead>
                        <tr>
                            <th>Bank</th>
                            <th>No. Rekening</th>
                            <th>Atas Nama</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rekening as $rek)
                            <tr>
                                <td>{{ $rek->bank }}</td>
                                <td>{{ $rek->norek }}</td>
                                <td>{{ $rek->atasnama }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p style="font-size:12px; color:#64748b;">Setelah melakukan pembayaran, unggah bukti transfer melalui portal peserta.</p>
            @endif

            <p style="font-size:14px;">Simpan email ini sebagai bukti pendaftaran Anda.
               Informasi lebih lanjut akan dikirimkan melalui email ini.</p>
        </div>

        <div class="footer">
            <p>Email ini dikirim otomatis — mohon tidak membalas.</p>
        </div>
    </div>
</body>
</html>
