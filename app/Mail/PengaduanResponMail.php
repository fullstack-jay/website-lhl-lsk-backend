<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Pengaduan;

class PengaduanResponMail extends Mailable implements ShouldQueue
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
        return new Envelope(
            subject: 'Respon Pengaduan Anda - ' . $this->pengaduan->no_pengaduan,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.pengaduan-respon',
            with: [
                'pengaduan' => $this->pengaduan,
                'respon' => $this->respon,
                'noPengaduan' => $this->pengaduan->no_pengaduan,
                'tanggalAduan' => $this->pengaduan->tanggal_waktu_formatted,
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
