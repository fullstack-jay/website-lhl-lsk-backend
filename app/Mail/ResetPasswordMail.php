<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $resetUrl,
        public string $roleName = "Pengguna",
        public int $expiresInMinutes = 60
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Permintaan Reset Kata Sandi - LSK Lingkungan Hidup Lestari",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: "emails.reset-password",
            with: [
                "userName" => $this->userName,
                "resetUrl" => $this->resetUrl,
                "roleName" => $this->roleName,
                "expiresInMinutes" => $this->expiresInMinutes,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
