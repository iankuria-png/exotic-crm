<?php

namespace Tests\Feature;

use App\Mail\WalletSettingsTestMail;
use App\Services\CrmMailerService;
use App\Services\WalletSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CrmMailerTest extends TestCase
{
    use RefreshDatabase;

    private function configure(array $extra = []): void
    {
        app(WalletSettingsService::class)->saveSystemConfig(['smtp' => array_merge([
            'enabled' => true, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'username' => 'support@example.test', 'password' => 'synthetic-password',
            'from_address' => 'support@example.test', 'from_name' => 'Support',
        ], $extra)]);
    }

    public function test_settings_canary_uses_shared_transport_and_does_not_change_global_sender(): void
    {
        Mail::fake();
        $original = config('mail.from');
        $this->configure();
        $context = app(CrmMailerService::class)->context();
        $this->assertSame('crm_smtp', $context['mailer']);
        $result = app(WalletSettingsService::class)->sendTestEmail('recipient@example.test');
        $this->assertSame($context['mailer'], $result['mailer']);
        Mail::assertSent(WalletSettingsTestMail::class, fn ($mail) => $mail->hasTo('recipient@example.test') && $mail->hasFrom('support@example.test'));
        $this->assertSame($original, config('mail.from'));
    }

    public function test_credential_delivery_uses_the_same_named_mailer_and_sender(): void
    {
        $this->configure();
        $mailer = \Mockery::mock(\Illuminate\Mail\Mailer::class);
        $mailer->shouldReceive('raw')->once()->with('Synthetic credential body', \Mockery::on(function ($callback) {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email);
            $callback($message);
            $this->assertSame('support@example.test', $message->getSymfonyMessage()->getFrom()[0]->getAddress());

            return true;
        }));
        Mail::shouldReceive('purge')->once()->with('crm_smtp');
        Mail::shouldReceive('mailer')->once()->with('crm_smtp')->andReturn($mailer);
        $method = new \ReflectionMethod(\App\Services\CredentialDeliveryService::class, 'sendEmail');
        $result = $method->invoke(app(\App\Services\CredentialDeliveryService::class), 'recipient@example.test', 'Synthetic test', 'Synthetic credential body');
        $this->assertTrue($result['success'], $result['provider_response']);
        $this->assertSame('crm_smtp', $result['provider']);
    }

    public function test_changed_settings_refresh_the_named_mailer_in_a_long_lived_process(): void
    {
        $this->configure();
        $service = app(CrmMailerService::class);
        $service->context();
        $first = app('mail.manager')->mailer('crm_smtp');
        $this->configure(['password' => 'changed-synthetic-password']);
        $service->context();
        $this->assertNotSame($first, app('mail.manager')->mailer('crm_smtp'));
        $this->assertSame('changed-synthetic-password', config('mail.mailers.crm_smtp.password'));
    }

    public function test_disabled_stored_smtp_preserves_default_delivery_but_canary_requires_configuration(): void
    {
        $this->configure(['enabled' => false]);
        $this->assertSame(config('mail.default'), app(CrmMailerService::class)->context()['mailer']);
        $this->expectException(\InvalidArgumentException::class);
        app(CrmMailerService::class)->context(requireStored: true);
    }
}
