<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Kata Sandi Akun LSK LHL</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1e293b;
            background-color: #f1f5f9;
            margin: 0;
            padding: 24px 12px;
        }
        .container {
            max-width: 580px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
            color: #ffffff;
            padding: 32px 24px;
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
            font-size: 13px;
            opacity: 0.92;
        }
        .content {
            padding: 32px 28px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 12px;
        }
        .message {
            font-size: 14px;
            color: #475569;
            margin-bottom: 24px;
            line-height: 1.65;
        }
        .btn-container {
            text-align: center;
            margin: 28px 0;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 13px 32px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            letter-spacing: 0.01em;
        }
        .btn:hover {
            opacity: 0.95;
        }
        .info-box {
            background-color: #f8fafc;
            border-left: 4px solid #0d9488;
            padding: 14px 18px;
            margin: 24px 0;
            border-radius: 6px;
            font-size: 13px;
            color: #334155;
        }
        .info-box p {
            margin: 0 0 6px 0;
        }
        .info-box p:last-child {
            margin: 0;
        }
        .link-alt {
            font-size: 12px;
            color: #64748b;
            word-break: break-all;
            background: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            border: 1px dashed #cbd5e1;
            margin-top: 8px;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px 24px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
        }
        .footer a {
            color: #0d9488;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Lembaga Sertifikasi Kompetensi (LSK)</h1>
            <p>Lingkungan Hidup Lestari &bull; Layanan Pengaturan Kata Sandi</p>
        </div>
        
        <div class="content">
            <div class="greeting">Halo, {{ $userName }}!</div>
            
            <p class="message">
                Kami menerima permintaan untuk mereset kata sandi akun <strong>{{ $roleName }}</strong> Anda pada portal sistem <strong>LSK Lingkungan Hidup Lestari</strong>.
                Silakan klik tombol di bawah ini untuk membuat kata sandi baru:
            </p>
            
            <div class="btn-container">
                <a href="{{ $resetUrl }}" class="btn" target="_blank">Ubah Kata Sandi Sekarang</a>
            </div>
            
            <div class="info-box">
                <p><strong>Perhatian Keamanan:</strong></p>
                <p>&bull; Tautan reset kata sandi ini hanya berlaku selama <strong>{{ $expiresInMinutes }} menit</strong>.</p>
                <p>&bull; Jika Anda tidak pernah merasa meminta reset kata sandi, abaikan email ini. Akun Anda tetap aman dan kata sandi Anda tidak akan berubah.</p>
            </div>
            
            <p class="message" style="font-size: 12px; color: #64748b; margin-top: 24px;">
                Jika tombol di atas tidak dapat diklik, salin dan tempelkan tautan berikut pada peramban web (browser) Anda:
            </p>
            <div class="link-alt">
                {{ $resetUrl }}
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; {{ date('Y') }} LSK Lingkungan Hidup Lestari. All rights reserved.</p>
            <p>Email ini dikirim secara otomatis oleh sistem, mohon untuk tidak membalas email ini.</p>
        </div>
    </div>
</body>
</html>
