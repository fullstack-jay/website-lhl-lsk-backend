<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use App\Models\Pengaduan;

/**
 * Email bukti tanda terima pengaduan & nomor tiket — dikirim ke pelapor saat submit pengaduan.
 * Menyematkan Message-ID deterministik agar respon admin di kemudian hari dapat menjadi thread reply di Gmail.
 */
class PengaduanTiketMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Instance pengaduan
     *
     * @var \App\Models\Pengaduan
     */
    public $pengaduan;

    /**
     * Create a new message instance.
     */
    public function __construct(Pengaduan $pengaduan)
    {
        $this->pengaduan = $pengaduan;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $noAduan = $this->pengaduan->no_pengaduan ?? ('ADU-' . $this->pengaduan->id);

        $fromAddress = config('mail.from.address', 'no-reply@lsk-lhl.com');
        $fromName = config('mail.from.name', 'LSK Lingkungan Hidup Lestari');

        return new Envelope(
            subject: 'Konfirmasi Pengaduan Anda - ' . $noAduan,
            replyTo: [
                new Address($fromAddress, $fromName),
            ],
        );
    }

    /**
     * Get the message headers.
     * Mengatur Message-ID unik berbasis nomor pengaduan
     */
    public function headers(): Headers
    {
        $noAduan = $this->pengaduan->no_pengaduan ?? ('ADU-' . $this->pengaduan->id);
        $cleanId = preg_replace('/[^a-zA-Z0-9]/', '', $noAduan);
        $messageId = "adu-{$this->pengaduan->id}-{$cleanId}@lsk-lhl.com";

        return new Headers(
            messageId: $messageId,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $noAduan = $this->pengaduan->no_pengaduan ?? ('ADU-' . $this->pengaduan->id);
        $tanggalAduan = $this->pengaduan->tanggal_waktu_formatted
            ?? $this->pengaduan->tanggal
            ?? now()->format('d/m/Y H:i');

        return new Content(
            view: 'emails.pengaduan-tiket',
            with: [
                'pengaduan' => $this->pengaduan,
                'noPengaduan' => $noAduan,
                'tanggalAduan' => $tanggalAduan,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
