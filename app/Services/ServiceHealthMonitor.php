<?php

namespace App\Services;

use App\Models\ServiceHealthEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Watches the analysis engine and says so when it stops (and when it returns).
 *
 * Run every minute by the scheduler. A change of state is written to
 * service_health_events straight away, so the history is exact. The alert is
 * held back until the problem has lasted GRACE_MINUTES, so a restart of the
 * service (a deploy, the supervisor bringing it back) does not page anyone.
 * The all-clear is sent only for a problem that was actually reported.
 *
 * Down means recordings cannot be scored. Degraded means they are measured but
 * no weakness can be classified (no model, or it is not usable).
 */
class ServiceHealthMonitor
{
    public const GRACE_MINUTES = 2;

    public function __construct(private MLClassificationService $ml)
    {
    }

    /** Probe now, record any change, send any alert that is due. */
    public function check(): ServiceHealthEvent
    {
        $status = $this->ml->serviceStatus(fresh: true);
        $level = $this->levelOf($status);

        $latest = ServiceHealthEvent::latest('id')->first();

        if (! $latest || $latest->state !== $status['state'] || $latest->level !== $level) {
            $previous = $latest;
            $latest = ServiceHealthEvent::create([
                'state' => $status['state'],
                'level' => $level,
                'message' => $status['message'],
            ]);

            if ($level === ServiceHealthEvent::HEALTHY && $previous && $this->wasAlerted($previous)) {
                $this->send(
                    'BIGKAS-AI analysis service is back',
                    $status['message'] . ' Recordings saved during the outage are being scored again automatically.'
                );
                $latest->update(['alerted_at' => now()]);
            }

            return $latest;
        }

        if (! $latest->isHealthy()
            && $latest->alerted_at === null
            && $latest->created_at->lte(now()->subMinutes(self::GRACE_MINUTES))) {
            $this->send(
                $level === ServiceHealthEvent::DOWN
                    ? 'BIGKAS-AI cannot score recordings'
                    : 'BIGKAS-AI is running without a usable model',
                $status['message'] . ' Recordings are still saved, and are scored automatically once it is back.'
            );
            $latest->update(['alerted_at' => now()]);
        }

        return $latest;
    }

    /** healthy | degraded | down, from MLClassificationService::serviceStatus(). */
    public function levelOf(array $status): string
    {
        if (($status['stt_ok'] ?? true) === false) {
            return ServiceHealthEvent::DOWN;
        }

        return ($status['ok'] ?? false) ? ServiceHealthEvent::HEALTHY : ServiceHealthEvent::DEGRADED;
    }

    private function wasAlerted(ServiceHealthEvent $event): bool
    {
        return ! $event->isHealthy() && $event->alerted_at !== null;
    }

    /**
     * The log always gets it; mail goes out when HEALTH_ALERT_EMAIL is set. A
     * mail failure must never stop the monitor, which is the thing that tells
     * you something else has.
     */
    private function send(string $subject, string $body): void
    {
        Log::warning("[service health] {$subject}: {$body}");

        $to = config('services.health_alert.email');
        if (! $to) {
            return;
        }

        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::error('[service health] alert email could not be sent: ' . $e->getMessage());
        }
    }
}
