<?php

namespace App\Services\Isapi;

use App\Models\Camera;
use App\Services\Ingestion\PlateEventRecorder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

/**
 * Holds a long-lived HTTP connection to one camera's ISAPI alertStream and
 * feeds everything it reads through the recorder.
 *
 * The camera never closes the connection in normal operation, so the loop only
 * ends when the process is asked to stop or the stream drops, at which point
 * the caller reconnects with backoff.
 */
class AlertStreamListener
{
    protected bool $shouldStop = false;

    /**
     * Read size in bytes. Small enough that a capture is handled promptly.
     */
    protected const CHUNK_BYTES = 4096;

    public function __construct(
        protected PlateEventRecorder $recorder,
        protected ?Client $client = null,
    ) {
        $this->client ??= new Client;
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Connect and consume until the stream ends. Returns the number of events
     * recorded during this connection.
     *
     * @param  callable(int): void|null  $onEvent  Called with the running count.
     */
    public function listen(Camera $camera, ?callable $onEvent = null): int
    {
        $stream = $this->openStream($camera);
        $parser = new AlertStreamParser;
        $recorded = 0;

        while (! $this->shouldStop && ! $stream->eof()) {
            $chunk = $stream->read(self::CHUNK_BYTES);

            if ($chunk === '') {
                // Nothing waiting; yield rather than spin the CPU.
                usleep(100_000);

                continue;
            }

            $captures = $parser->push($chunk);

            if ($captures === []) {
                continue;
            }

            $recorded += $this->recorder->recordMany($camera, $captures);

            if ($onEvent !== null) {
                $onEvent($recorded);
            }
        }

        $stream->close();

        return $recorded;
    }

    /**
     * @throws GuzzleException
     */
    protected function openStream(Camera $camera): StreamInterface
    {
        $response = $this->client->request('GET', $camera->alertStreamUrl(), [
            RequestOptions::AUTH => [$camera->isapi_username, $camera->isapi_password, 'digest'],
            RequestOptions::STREAM => true,
            RequestOptions::TIMEOUT => (float) config('trafficflow.alert_stream.timeout'),
            RequestOptions::CONNECT_TIMEOUT => (float) config('trafficflow.alert_stream.connect_timeout'),
            RequestOptions::HEADERS => ['Accept' => 'multipart/mixed'],
        ]);

        return $response->getBody();
    }

    /**
     * Confirm the camera answers before committing to a long-lived stream, and
     * record the outcome for the Cameras page.
     */
    public function probe(Camera $camera): bool
    {
        try {
            $this->client->request('GET', $camera->probeUrl(), [
                RequestOptions::AUTH => [$camera->isapi_username, $camera->isapi_password, 'digest'],
                RequestOptions::TIMEOUT => (float) config('trafficflow.alert_stream.connect_timeout'),
            ]);
        } catch (GuzzleException $e) {
            $camera->forceFill(['last_probe_error' => str($e->getMessage())->limit(240)->value()])->saveQuietly();

            Log::warning('Camera probe failed', [
                'camera_id' => $camera->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $camera->forceFill([
            'last_probe_ok_at' => now(),
            'last_probe_error' => null,
        ])->saveQuietly();

        return true;
    }
}
