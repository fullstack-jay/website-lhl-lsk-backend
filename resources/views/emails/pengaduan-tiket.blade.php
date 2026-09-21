<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Pengaduan Anda - {{ $noPengaduan }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1e293b;
            background-color: #f1f5f9;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #e11d48 0%, #be185d 100%);
            color: white;
            padding: 32px 28px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .header p {
            margin: 8px 0 0 0;
            opacity: 0.95;
            font-size: 14px;
        }
        .badge-ticket {
            display: inline-block;
            background-color: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.35);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 15px;
            font-weight: 700;
            font-family: monospace, Courier, sans-serif;
            margin-top: 12px;
            letter-spacing: 0.05em;
        }
        .content {
            padding: 28px 28px 24px 28px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 12px;
        }
        .info-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #e11d48;
            padding: 16px 18px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            width: 130px;
            font-weight: 600;
            color: #64748b;
        }
        .info-val {
            color: #1e293b;
            font-weight: 500;
        }
        .aduan-box {
            background-color: #fff1f2;
            border: 1px solid #ffe4e6;
            padding: 18px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .aduan-box h3 {
            margin: 0 0 10px 0;
            color: #9f1239;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .aduan-box p {
            margin: 0;
            color: #4c0519;
            line-height: 1.6;
            font-size: 14px;
            white-space: pre-wrap;
        }
        .notice-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 16px 18px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .notice-box h4 {
            margin: 0 0 8px 0;
            color: #166534;
            font-size: 14px;
            font-weight: 700;
        }
        .notice-box p {
            margin: 0;
            color: #14532d;
            font-size: 13px;
            line-height: 1.5;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            background-color: #fef3c7;
            color: #92400e;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px 28px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 4px 0;
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Bukti Pengaduan Layanan</h1>
            <p>Lembaga Sertifikasi Kompetensi Lingkungan Hidup Lestari</p>
            <div class="badge-ticket">{{ $noPengaduan }}</div>
        </div>

        <div class="content">
            <p class="greeting">Halo, {{ $pengaduan->nama }}</p>
            <p style="font-size: 14px; color: #475569; margin-top: 0;">
                Terima kasih telah menyampaikan pengaduan Anda. Laporan Anda telah berhasil kami terima dan terdaftar dalam sistem pengaduan LSK LHL dengan rincian sebagai berikut:
            </p>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Nomor Tiket:</span>
                    <span class="info-val"><strong style="color: #e11d48; font-family: monospace;">{{ $noPengaduan }}</strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Tanggal Masuk:</span>
                    <span class="info-val">{{ $tanggalAduan }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Pelapor:</span>
                    <span class="info-val">{{ $pengaduan->nama }} ({{ ucfirst($pengaduan->jenis_responden ?? 'Masyarakat') }})</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status Awal:</span>
                    <span class="info-val"><span class="status-badge">Menunggu</span></span>
                </div>
            </div>

            <div class="aduan-box">
                <h3>📝 Isi Pengaduan Anda:</h3>
                <p>{{ $pengaduan->aduan }}</p>
            </div>

            <div class="notice-box">
                <h4>ℹ️ Informasi Tindak Lanjut:</h4>
                <p>
                    Pengaduan Anda sedang dalam antrean peninjauan oleh tim admin LSK LHL. 
                    Setiap tanggapan, klarifikasi, atau penyelesaian yang diberikan oleh admin <strong>akan otomatis dikirimkan ke email ini sebagai balasan resmi</strong>.
                </p>
                <p style="margin-top: 8px;">
                    Mohon simpan nomor tiket <strong>{{ $noPengaduan }}</strong> ini sebagai bukti tanda terima dan referensi pelacakan pengaduan Anda.
                </p>
            </div>

            <p style="font-size: 14px; color: #475569; margin-top: 24px;">
                Hormat kami,<br>
                <strong>Tim Layanan Pengaduan</strong><br>
                LSK Lingkungan Hidup Lestari
            </p>
        </div>

        <div class="footer">
            <p>Email ini dikirim secara otomatis sebagai konfirmasi tanda terima pengaduan Anda.</p>
            <p>&copy; {{ date('Y') }} LSK Lingkungan Hidup Lestari. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
