<?php

namespace App\Enums;

/**
 * How a camera's plate captures reach us.
 *
 * A single camera can be configured for exactly one primary path, but the
 * recorder's dedupe means running two paths in parallel is safe: the mode is
 * about *which paths the app should actively expect*, not which ones it
 * accepts. `Auto` accepts whatever arrives without complaint.
 */
enum IngestionMode: string
{
    /** Camera POSTs events outbound to our webhook. Default for new devices. */
    case Webhook = 'webhook';

    /** We hold an ISAPI alertStream connection open to the camera. LAN-only. */
    case Stream = 'stream';

    /** Camera FTPs captures into a drop folder we sweep. Legacy fallback. */
    case Ftp = 'ftp';

    /** Accept anything; do not raise an alert if a path is quiet. */
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Webhook => 'HTTP webhook (recommended)',
            self::Stream => 'ISAPI alert stream (LAN)',
            self::Ftp => 'FTP drop folder',
            self::Auto => 'Any',
        };
    }

    /**
     * Whether the app should actively try to reach the camera on its LAN IP.
     * Webhook and FTP cameras are dial-home only, so we do not probe them.
     */
    public function needsInboundReach(): bool
    {
        return $this === self::Stream;
    }
}
