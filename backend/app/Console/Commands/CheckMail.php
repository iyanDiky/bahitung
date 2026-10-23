<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CheckMail extends Command
{
    protected $signature = 'mail:check {--send-to= : Kirim satu email uji ke alamat ini setelah konfigurasi lolos}';

    protected $description = 'Periksa konfigurasi SMTP tanpa menampilkan rahasia; pengiriman hanya dengan --send-to';

    public function handle(): int
    {
        if (config('mail.default') !== 'smtp') {
            $this->error('Email nyata belum aktif. Atur MAIL_MAILER=smtp setelah melengkapi konfigurasi SMTP.');

            return self::FAILURE;
        }

        if (filled(config('mail.mailers.smtp.url'))) {
            $this->error('Pemeriksaan ini memakai MAIL_HOST/PORT/USERNAME/PASSWORD. Kosongkan MAIL_URL agar tidak menimpa konfigurasi tersebut.');

            return self::INVALID;
        }

        foreach (['host', 'username', 'password'] as $field) {
            if (blank(config('mail.mailers.smtp.'.$field))) {
                $this->error('Konfigurasi SMTP belum lengkap: MAIL_'.strtoupper($field).'.');

                return self::INVALID;
            }
        }

        $port = filter_var(config('mail.mailers.smtp.port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $scheme = config('mail.mailers.smtp.scheme');
        if (! $port || ! in_array($scheme, [null, 'smtp', 'smtps'], true)) {
            $this->error('MAIL_PORT atau MAIL_SCHEME tidak valid. Gunakan smtp atau smtps sesuai layanan.');

            return self::INVALID;
        }

        $from = config('mail.from.address');
        if (! is_string($from) || ! filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/@(example\.(com|org|net)|[^@]+\.(test|invalid|example))$/i', $from)) {
            $this->error('MAIL_FROM_ADDRESS harus berupa alamat pengirim nyata yang diizinkan layanan.');

            return self::INVALID;
        }

        foreach (['app.url', 'app.frontend_url'] as $key) {
            $url = config($key);
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $this->error('APP_URL dan FRONTEND_URL harus berupa URL HTTP/HTTPS yang valid.');

                return self::INVALID;
            }
        }

        $recipient = $this->option('send-to');
        if ($recipient !== null && (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL))) {
            $this->error('Alamat penerima email uji tidak valid.');

            return self::INVALID;
        }

        $this->info('Konfigurasi SMTP lengkap. Koneksi, kredensial, dan izin pengirim belum diverifikasi.');
        if ($recipient === null) {
            $this->line('Tidak ada email dikirim. Gunakan --send-to=alamat untuk pengujian pengiriman.');

            return self::SUCCESS;
        }

        try {
            Mail::raw('Ini adalah email uji bahitung. Tidak ada tindakan atau perubahan akun yang diperlukan.', function (Message $message) use ($recipient): void {
                $message->to($recipient)->subject('Uji pengiriman email bahitung');
            });
        } catch (Throwable) {
            $this->error('Pengiriman gagal. Periksa koneksi SMTP, kredensial, TLS, serta izin pengirim pada layanan email.');

            return self::FAILURE;
        }

        $this->info('Server SMTP menerima email uji. Periksa inbox/spam penerima untuk memastikan email sampai.');

        return self::SUCCESS;
    }
}
