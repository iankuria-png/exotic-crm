<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WalletSettingsTestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $senderName,
        private readonly string $mode
    ) {
    }

    public function build(): self
    {
        $modeLabel = ucfirst($this->mode);

        return $this
            ->subject("{$this->senderName} wallet email test ({$modeLabel})")
            ->html(sprintf(
                '<p>This is a wallet settings test email from <strong>%s</strong>.</p><p>Current mode: <strong>%s</strong>.</p>',
                e($this->senderName),
                e($modeLabel)
            ));
    }
}
