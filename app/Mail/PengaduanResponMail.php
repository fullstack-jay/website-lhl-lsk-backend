<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Pengaduan;

/**
 * Email respon pengaduan — dikirim LANGSUNG (sync, bukan queue) agar admin
 * langsung tahu sukses/gagal saat tombol ditekan (realtime, bukan antrean).
 * Fallback no_pengaduan: kolom bisa NULL di data legacy → pakai ADU-{id}.
 */
class PengaduanResponMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The pengaduan instance.
     *
     * @var \App\Models\Pengaduan
     */
    public $pengaduan;

    /**
     * The respon message.
     *
     * @var string
     */
    public $respon;

    /**
     * Create a new message instance.
     */
    public function __construct(Pengaduan $pengaduan, string $respon)
    {
        $this->pengaduan = $pengaduan;
        $this->respon = $respon;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $noAduan = $this->pengaduan->no_pengaduan ?? ('ADU-' . $this->pengaduan->id);

        return new Envelope(
            subject: 'Respon Pengaduan Anda - ' . $noAduan,
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
            view: 'emails.pengaduan-respon',
            with: [
                'pengaduan' => $this->pengaduan,
                'respon' => $this->respon,
                'noPengaduan' => $noAduan,
                'tanggalAduan' => $tanggalAduan,
            ]
        );
    }

    /**
     * Get the attachments for the image.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
