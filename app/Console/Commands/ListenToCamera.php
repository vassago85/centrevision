<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Models\Scopes\SiteScope;
use App\Services\Isapi\AlertStreamListener;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Long-running listener for a single camera, intended to be supervised.
 *
 * See supervisor/trafficflow-listen.conf for a worked example of running one
 * of these per camera.
 */
class ListenToCamera extends Command
{
    protected $signature = 'traffic:listen
        {camera : Camera id}
        {--once : Stop after the stream first drops instead of reconnecting}';

    protected $description = 'Consume a Hikvision ISAPI alert stream and record plate events';

    public function handle(AlertStreamListener $listener): int
    {
        $camera = Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->find($this->argument('camera'));

        if ($camera === null) {
            $this->components->error('Camera not found.');

            return self::FAILURE;
        }

        if (! $camera->is_active) {
            $this->components->warn("Camera [{$camera->name}] is inactive; nothing to do.");

            return self::SUCCESS;
        }

        $this->trapSignals($listener);

        $attempt = 0;

        while (true) {
            $this->components->info("Connecting to {$camera->name} ({$camera->ip_address})");

            try {
                $recorded = $listener->listen($camera, function (int $count): void {
                    $this->output->write("\rEvents recorded: {$count}");
                });

                $this->newLine();
                $this->components->info("Stream ended after {$recorded} event(s).");

                // A clean end still means the camera went away, so treat it as
                // a drop and reconnect unless asked not to.
                $attempt++;
            } catch (\Throwable $e) {
                $attempt++;

                $this->components->error("Stream failed: {$e->getMessage()}");

                Log::warning('Alert stream dropped', [
                    'camera_id' => $camera->getKey(),
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            $delay = $this->backoffSeconds($attempt);
            $this->components->warn("Reconnecting in {$delay}s.");
            sleep($delay);
        }
    }

    /**
     * Exponential backoff, clamped so a camera that is down for a weekend is
     * still retried regularly.
     */
    protected function backoffSeconds(int $attempt): int
    {
        $base = (int) config('trafficflow.alert_stream.retry_base_seconds');
        $max = (int) config('trafficflow.alert_stream.retry_max_seconds');

        return (int) min($max, $base * (2 ** min($attempt - 1, 10)));
    }

    /**
     * Let supervisor stop the listener without severing a half-parsed event.
     */
    protected function trapSignals(AlertStreamListener $listener): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $stop = function () use ($listener): void {
            $this->newLine();
            $this->components->info('Stopping.');
            $listener->stop();
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
