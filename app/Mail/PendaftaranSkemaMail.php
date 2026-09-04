<?php

namespace App\Mail;

use App\Models\Asesi;
use App\Models\SkemaKkni;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email konfirmasi pendaftaran skema sertifikasi (FR-APL-01)
 * — step 3 daftarasesmen, dikirim SETELAH commit DB
 * (kegagalan SMTP tidak menggagalkan pendaftaran).
 */
class PendaftaranSkemaMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Asesi $asesi,
        public SkemaKkni $skema,
        public int $biaya,
        public $rekening
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Konfirmasi Pendaftaran Skema Sertifikasi - '.$this->skema->kode_skema,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pendaftaran-skema',
            with: [
                'asesi' => $this->asesi,
                'skema' => $this->skema,
                'biaya' => $this->biaya,
                'biayaFormatted' => 'Rp. '.number_format($this->biaya, 0, ',', '.'),
                'rekening' => $this->rekening,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
