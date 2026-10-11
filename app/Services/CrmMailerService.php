<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class CrmMailerService
{
    public const MAILER = 'crm_smtp';

    public function __construct(private readonly WalletSettingsService $settings) {}

    public function context(bool $requireStored = false): array
    {
        $smtp = $this->settings->currentSystemConfig(masked: false)['smtp'] ?? [];
        if (! ($smtp['enabled'] ?? false)) {
            if ($requireStored) {
                throw new InvalidArgumentException('CRM SMTP delivery is disabled.');
            }

            return ['mailer' => config('mail.default'), 'address' => config('mail.from.address'), 'name' => config('mail.from.name')];
        }
        foreach (['host', 'port', 'username', 'password', 'from_address'] as $field) {
            if (! filled($smtp[$field] ?? null)) {
                throw new InvalidArgumentException('SMTP settings are incomplete.');
            }
        }
        $port = filter_var($smtp['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $encryption = $smtp['encryption'] ?: null;
        if (! $port || ! filter_var($smtp['from_address'], FILTER_VALIDATE_EMAIL)
            || ! in_array($encryption, [null, 'tls', 'ssl'], true)
            || preg_match('/[\s\x00-\x1f]/', (string) $smtp['host'])) {
            throw new InvalidArgumentException('SMTP settings are invalid.');
        }
        config(['mail.mailers.'.self::MAILER => [
            'transport' => 'smtp', 'host' => (string) $smtp['host'], 'port' => $port,
            'encryption' => $encryption, 'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'username' => (string) $smtp['username'], 'password' => (string) $smtp['password'], 'timeout' => 10,
        ]]);
        // A long-lived worker must discard the old connection after settings change.
        app('mail.manager')->purge(self::MAILER);

        return ['mailer' => self::MAILER, 'address' => (string) $smtp['from_address'],
            'name' => (string) ($smtp['from_name'] ?: config('app.name', 'ExoticCRM'))];
    }

    public function raw(string $to, string $subject, string $body, bool $requireStored = false): string
    {
        $context = $this->context($requireStored);
        Mail::mailer($context['mailer'])->raw($body, function ($message) use ($to, $subject, $context) {
            $message->to($to)->subject($subject)->from($context['address'], $context['name']);
        });

        return $context['mailer'];
    }

    public function send(string $to, Mailable $message, bool $requireStored = false): string
    {
        $context = $this->context($requireStored);
        Mail::mailer($context['mailer'])->to($to)->send($message->from($context['address'], $context['name']));

        return $context['mailer'];
    }
}
