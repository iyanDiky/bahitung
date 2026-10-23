<?php

namespace Tests\Feature;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.scheme' => 'smtp', 'mail.mailers.smtp.username' => 'private-user',
            'mail.mailers.smtp.password' => 'private-secret', 'mail.from.address' => 'sender@bahitung.id',
            'app.url' => 'http://127.0.0.1:8000', 'app.frontend_url' => 'http://127.0.0.1:5173']);
    }

    public function test_default_check_never_sends_and_does_not_print_credentials(): void
    {
        Mail::shouldReceive('raw')->never();
        $this->artisan('mail:check')->expectsOutput('Tidak ada email dikirim. Gunakan --send-to=alamat untuk pengujian pengiriman.')
            ->doesntExpectOutputToContain('private-secret')->doesntExpectOutputToContain('private-user')->assertSuccessful();
    }

    public function test_log_mailer_cannot_be_reported_as_real_delivery(): void
    {
        config(['mail.default' => 'log']);
        Mail::shouldReceive('raw')->never();
        $this->artisan('mail:check', ['--send-to' => 'test@example.test'])->assertFailed();
    }

    public function test_missing_credentials_fail_without_sending(): void
    {
        config(['mail.mailers.smtp.password' => '']);
        Mail::shouldReceive('raw')->never();
        $this->artisan('mail:check')->assertExitCode(2);
    }

    public function test_placeholder_sender_and_url_override_are_rejected(): void
    {
        Mail::shouldReceive('raw')->never();
        config(['mail.from.address' => 'hello@bahitung.test']);
        $this->artisan('mail:check')->assertExitCode(2);
        config(['mail.from.address' => 'sender@bahitung.id', 'mail.mailers.smtp.url' => 'smtp://private-secret@host']);
        $this->artisan('mail:check')->doesntExpectOutputToContain('private-secret')->assertExitCode(2);
    }

    public function test_invalid_recipient_fails_before_sending(): void
    {
        Mail::shouldReceive('raw')->never();
        $this->artisan('mail:check', ['--send-to' => 'invalid'])->assertExitCode(2);
    }

    public function test_explicit_recipient_gets_only_one_non_sensitive_test_email(): void
    {
        Mail::shouldReceive('raw')->once()->withArgs(function (string $body, callable $callback): bool {
            $message = new Message(new Email);
            $callback($message);
            $this->assertSame('recipient@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $this->assertSame('Uji pengiriman email bahitung', $message->getSymfonyMessage()->getSubject());
            $this->assertStringNotContainsString('private-secret', $body);

            return true;
        });
        $this->artisan('mail:check', ['--send-to' => 'recipient@example.test'])->expectsOutput('Server SMTP menerima email uji. Periksa inbox/spam penerima untuk memastikan email sampai.')->assertSuccessful();
    }

    public function test_transport_error_does_not_expose_secret(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('smtp://private-user:private-secret@host'));
        $this->artisan('mail:check', ['--send-to' => 'recipient@example.test'])->doesntExpectOutputToContain('private-secret')->assertFailed();
    }
}
