<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respon Pengaduan Anda</title>
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
            background: linear-gradient(135deg, #e11d48 0%, #be185d 100%);
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
            border-left: 4px solid #e11d48;
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
            min-width: 120px;
        }
        .aduan-box {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .aduan-box h3 {
            margin: 0 0 10px 0;
            color: #991b1b;
            font-size: 16px;
        }
        .aduan-box p {
            margin: 0;
            color: #7f1d1d;
            line-height: 1.6;
        }
        .respon-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .respon-box h3 {
            margin: 0 0 10px 0;
            color: #166534;
            font-size: 16px;
        }
        .respon-box p {
            margin: 0;
            color: #14532d;
            line-height: 1.6;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 5px 0;
            font-size: 13px;
            color: #64748b;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-waiting { background-color: #fef3c7; color: #92400e; }
        .status-processing { background-color: #dbeafe; color: #1e40af; }
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-archived { background-color: #e2e8f0; color: #475569; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📬 Respon Pengaduan Anda</h1>
            <p>Nomor: {{ $noPengaduan }}</p>
        </div>

        <div class="content">
            <p>Yth. {{ $pengaduan->nama }},</p>
            <p>Terima kasih telah mengirimkan pengaduan kepada kami. Berikut adalah informasi pengaduan dan respon dari tim kami:</p>

            <div class="info-box">
                <p><strong>Nomor Aduan:</strong> {{ $noPengaduan }}</p>
                <p><strong>Tanggal:</strong> {{ $tanggalAduan }}</p>
                <p><strong>Status:</strong> <span class="status-badge status-{{ $pengaduan->status }}">{{ $pengaduan->status }}</span></p>
            </div>

            <div class="aduan-box">
                <h3>📝 Isi Pengaduan Anda:</h3>
                <p>{{ $pengaduan->aduan }}</p>
            </div>

            <div class="respon-box">
                <h3>✉️ Respon dari Admin:</h3>
                <p>{{ $respon }}</p>
            </div>

            <p>Jika Anda memiliki pertanyaan lebih lanjut, silakan hubungi kami kembali dengan menyebutkan nomor pengaduan Anda.</p>

            <p>Hormat kami,<br><strong>Tim Layanan Pengaduan</strong></p>
        </div>

        <div class="footer">
            <p>Email ini dikirim secara otomatis. Mohon tidak membalas email ini.</p>
            <p>&copy; {{ date('Y') }} Layanan Pengaduan. Semua hak dilindungi.</p>
        </div>
    </div>
</body>
</html>
