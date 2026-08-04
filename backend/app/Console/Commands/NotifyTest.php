<?php

namespace App\Console\Commands;

use App\Models\NotificationChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Send one real message down a configured channel.
 *
 * Worth its own command because everything up to the last hop can be right
 * while the hop itself is broken, and the only way to find out is to make it.
 * A channel that has never delivered anything is a channel nobody has tested.
 */
class NotifyTest extends Command
{
    protected $signature = 'alarms:test-notification {channel? : channel key, or all enabled}';

    protected $description = 'Send a test message through a notification channel';

    public function handle(): int
    {
        $channels = NotificationChannel::query()
            ->when($this->argument('channel'), fn ($q, $key) => $q->where('key', $key))
            ->where('enabled', true)
            ->get();

        if ($channels->isEmpty()) {
            $this->error('No enabled channel matches. See alarms:channel.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($channels as $channel) {
            $this->line("{$channel->key} ({$channel->transport}) ...");

            try {
                match ($channel->transport) {
                    'log' => logger()->warning('QuakeVault test notification'),
                    'email' => $this->email($channel),
                    'webhook' => throw new \RuntimeException(
                        'webhook test not implemented; raise a real alarm to exercise it',
                    ),
                    default => throw new \RuntimeException("unknown transport {$channel->transport}"),
                };
                $this->info('  delivered');
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  '.$e->getMessage());

                // The two that actually happen with Gmail, named rather than
                // left as a stack trace.
                if (str_contains($e->getMessage(), 'Username and Password not accepted')) {
                    $this->line('  -> MAIL_PASSWORD must be a 16-character app password,');
                    $this->line('     not the account password. Gmail rejects the latter.');
                }
                if (str_contains($e->getMessage(), 'PUT_YOUR')) {
                    $this->line('  -> MAIL_PASSWORD is still the placeholder in backend/.env');
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function email(NotificationChannel $channel): void
    {
        $recipients = $channel->config['recipients'] ?? [];

        if ($recipients === []) {
            throw new \RuntimeException('no recipients configured on this channel');
        }

        Mail::raw(
            "This is a test from the QuakeVault SHM appliance.\n\n"
            ."If you are reading it, alarms from this appliance can reach you.\n"
            ."Sent at ".now()->toDateTimeString()." from ".config('app.name').".",
            fn ($m) => $m->to($recipients)->subject('QuakeVault test notification'),
        );
    }
}
