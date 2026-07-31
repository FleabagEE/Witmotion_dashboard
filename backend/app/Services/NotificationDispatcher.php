<?php

namespace App\Services;

use App\Models\AlarmEvent;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Turns an alarm transition into somebody actually being told.
 *
 * Almost all of the value here is in the messages it decides *not* to send. An
 * alarm system that floods people is worse than one that stays silent, because
 * a flooded channel gets muted and then the one message that mattered is missed
 * too. So every send passes four gates: severity floor, provisional status,
 * deduplication, and a rate ceiling - and every suppression is recorded with its
 * reason, so "why was I not told" always has an answer.
 */
class NotificationDispatcher
{
    /** @var array<string, callable(array): void> */
    private array $transports;

    public function __construct(?array $transports = null)
    {
        $this->transports = $transports ?? [
            'log' => fn (array $message) => Log::channel('stack')->warning(
                '[alarm] '.$message['subject'], ['body' => $message['body']],
            ),
            'email' => fn (array $message) => $this->sendEmail($message),
            'webhook' => fn (array $message) => $this->sendWebhook($message),
        ];
    }

    /**
     * @return array<int, array{channel: string, status: string, reason: ?string}>
     */
    public function dispatch(AlarmEvent $event): array
    {
        $results = [];
        $event->loadMissing('definition');

        // Integrations hear about every alarm, including provisional ones -
        // they are consuming data, not being paged - but the flag travels with
        // it so nothing downstream can mistake one for confirmed.
        try {
            app(MqttPublisher::class)->publishAlarm($event);
        } catch (\Throwable $exception) {
            Log::warning('mqtt alarm publish failed', ['error' => $exception->getMessage()]);
        }

        // Escalation targets are excluded here: they exist to hear about
        // alarms nobody acknowledged, not to receive the first message too.
        $channels = NotificationChannel::where('enabled', true)
            ->where('escalation_only', false)
            ->get();

        foreach ($channels as $channel) {
            $results[] = $this->deliverTo($channel, $event);
        }

        return $results;
    }

    private function deliverTo(NotificationChannel $channel, AlarmEvent $event): array
    {
        $level = $event->level;
        $now = now();
        $dedupeKey = $this->dedupeKey($channel, $event);
        $subject = $this->subject($event);
        $body = $this->body($event);

        $suppress = function (string $reason) use ($channel, $event, $dedupeKey, $level, $subject, $body): array {
            $this->record($channel, $event, $dedupeKey, $level, $subject, $body, 'suppressed', $reason);

            return ['channel' => $channel->key, 'status' => 'suppressed', 'reason' => $reason];
        };

        // 1. An alarm raised from thresholds nobody has confirmed must never
        //    page anyone. It is visible on the dashboard; that is the extent of
        //    what unverified numbers have earned.
        if ($event->provisional) {
            return $suppress('provisional_thresholds');
        }

        if ($event->isShelved()) {
            return $suppress('shelved');
        }

        // 2. Severity floor, then quiet hours.
        if (! $channel->carries($level)) {
            return ['channel' => $channel->key, 'status' => 'skipped', 'reason' => 'below_min_level'];
        }
        if ($channel->isQuietFor($level, $now)) {
            return $suppress('quiet_hours');
        }

        // 3. The same condition on the same channel, again, within the window.
        //    A window of zero disables deduplication outright; without this a
        //    zero window still collapses everything sent in the same second.
        if ($channel->dedupe_window_seconds > 0) {
            $recentDuplicate = DB::table('notification_deliveries')
                ->where('notification_channel_id', $channel->id)
                ->where('dedupe_key', $dedupeKey)
                ->where('status', 'sent')
                ->where('created_at', '>=', $now->copy()->subSeconds($channel->dedupe_window_seconds))
                ->exists();
            if ($recentDuplicate) {
                return $suppress('duplicate');
            }
        }

        // 4. Volume ceiling, so a flapping input cannot empty a mailbox.
        $sentThisHour = DB::table('notification_deliveries')
            ->where('notification_channel_id', $channel->id)
            ->where('status', 'sent')
            ->where('created_at', '>=', $now->copy()->subHour())
            ->count();
        if ($sentThisHour >= $channel->max_per_hour) {
            return $suppress('rate_limited');
        }

        $deliveryId = $this->record($channel, $event, $dedupeKey, $level, $subject, $body, 'pending');

        try {
            $transport = $this->transports[$channel->transport] ?? null;
            if ($transport === null) {
                throw new \RuntimeException("no transport for '{$channel->transport}'");
            }
            $transport([
                'channel' => $channel,
                'subject' => $subject,
                'body' => $body,
                'event' => $event,
            ]);

            DB::table('notification_deliveries')->where('id', $deliveryId)->update([
                'status' => 'sent', 'attempts' => 1, 'sent_at' => now(),
            ]);

            return ['channel' => $channel->key, 'status' => 'sent', 'reason' => null];
        } catch (\Throwable $exception) {
            // A failed notification is itself an event worth keeping: "nobody was
            // told" is the thing an investigation needs to establish.
            DB::table('notification_deliveries')->where('id', $deliveryId)->update([
                'status' => 'failed', 'attempts' => 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            Log::error('notification failed', [
                'channel' => $channel->key, 'error' => $exception->getMessage(),
            ]);

            return ['channel' => $channel->key, 'status' => 'failed', 'reason' => $exception->getMessage()];
        }
    }

    /**
     * Deliveries that were sent but never acknowledged, past the escalation delay.
     *
     * Run from the scheduler. An alarm nobody acknowledged is the case
     * escalation exists for - the first message may have gone to a phone that
     * was face-down on a desk.
     */
    public function escalateStale(): int
    {
        $escalated = 0;

        $channels = NotificationChannel::where('enabled', true)
            ->whereNotNull('escalate_after_minutes')
            ->whereNotNull('escalates_to')
            ->get();

        foreach ($channels as $channel) {
            $cutoff = now()->subMinutes($channel->escalate_after_minutes);
            $target = NotificationChannel::where('key', $channel->escalates_to)->first();
            if (! $target) {
                continue;
            }

            $stale = DB::table('notification_deliveries as d')
                ->join('alarm_events as e', 'e.id', '=', 'd.alarm_event_id')
                ->where('d.notification_channel_id', $channel->id)
                ->where('d.status', 'sent')
                ->where('d.sent_at', '<=', $cutoff)
                ->where('e.state', 'active')
                ->whereNull('e.acknowledged_at')
                ->pluck('e.id')
                ->unique();

            foreach ($stale as $eventId) {
                $event = AlarmEvent::find($eventId);
                if ($event === null) {
                    continue;
                }
                $result = $this->deliverTo($target, $event);
                if ($result['status'] === 'sent') {
                    $escalated++;
                }
            }
        }

        return $escalated;
    }

    private function dedupeKey(NotificationChannel $channel, AlarmEvent $event): string
    {
        // Keyed on the condition, not the event id: a re-raised alarm on the
        // same channel at the same level is the same news.
        return sprintf(
            '%s:%s:%s:%s',
            $channel->key, $event->sensor_id, $event->channel_key, $event->level,
        );
    }

    private function subject(AlarmEvent $event): string
    {
        return sprintf(
            '[%s] %s on %s',
            strtoupper($event->level),
            $event->definition?->name ?? 'Alarm',
            $event->channel_key,
        );
    }

    private function body(AlarmEvent $event): string
    {
        $lines = [
            $event->definition?->name ?? 'Alarm',
            '',
            sprintf('Level:      %s', $event->level),
            sprintf('Channel:    %s', $event->channel_key),
            sprintf('Value:      %s %s', $event->trigger_value, $event->unit),
            sprintf('Threshold:  %s %s', $event->threshold ?? 'n/a', $event->unit),
            sprintf('Raised:     %s', $event->raised_at?->toDateTimeString() ?? '-'),
        ];

        if ($event->definition?->thresholds_confirmed_by) {
            $lines[] = sprintf(
                'Thresholds: confirmed by %s against %s',
                $event->definition->thresholds_confirmed_by,
                $event->definition->thresholds_reference ?? 'an unrecorded source',
            );
        }

        return implode("\n", $lines);
    }

    private function record(
        NotificationChannel $channel,
        AlarmEvent $event,
        string $dedupeKey,
        string $level,
        string $subject,
        string $body,
        string $status,
        ?string $reason = null,
    ): int {
        return DB::table('notification_deliveries')->insertGetId([
            'alarm_event_id' => $event->id,
            'notification_channel_id' => $channel->id,
            'dedupe_key' => $dedupeKey,
            'level' => $level,
            'subject' => mb_substr($subject, 0, 240),
            'body' => $body,
            'status' => $status,
            'suppressed_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function sendEmail(array $message): void
    {
        $recipients = $message['channel']->config['recipients'] ?? [];
        if ($recipients === []) {
            throw new \RuntimeException('email channel has no recipients configured');
        }

        Mail::raw($message['body'], function ($mail) use ($recipients, $message): void {
            $mail->to($recipients)->subject($message['subject']);
        });
    }

    private function sendWebhook(array $message): void
    {
        $url = $message['channel']->config['url'] ?? null;
        if (! $url) {
            throw new \RuntimeException('webhook channel has no url configured');
        }

        $payload = json_encode([
            'subject' => $message['subject'],
            'level' => $message['event']->level,
            'channel_key' => $message['event']->channel_key,
            'value' => $message['event']->trigger_value,
            'unit' => $message['event']->unit,
            'raised_at' => $message['event']->raised_at?->toIso8601String(),
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    .implode("\r\n", $message['channel']->config['headers'] ?? []),
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("webhook POST to {$url} failed");
        }
    }
}
