<?php

use App\Jobs\ProcessHikvisionWebhook;
use App\Support\DiskUsage;
use Illuminate\Support\Facades\Storage;

it('formats byte counts the way a dashboard should read them', function () {
    expect(DiskUsage::formatBytes(0))->toBe('0 B')
        ->and(DiskUsage::formatBytes(512))->toBe('512 B')
        ->and(DiskUsage::formatBytes(1024))->toBe('1 KB')
        ->and(DiskUsage::formatBytes(1536))->toBe('1.5 KB')
        ->and(DiskUsage::formatBytes(10 * 1024 * 1024))->toBe('10 MB');
});

it('warns when the volume is mostly full', function () {
    $ok = new DiskUsage(100, 40, 60, 60.0, 0);
    $warn = new DiskUsage(100, 20, 80, 80.0, 0);
    $full = new DiskUsage(100, 5, 95, 95.0, 0);

    expect($ok->variant())->toBe('default')
        ->and($warn->variant())->toBe('warn')
        ->and($full->variant())->toBe('danger')
        ->and($ok->percentLabel())->toBe('60%');
});

it('counts plate-capture files on the local disk', function () {
    Storage::fake('local');
    Storage::disk('local')->put(ProcessHikvisionWebhook::CAPTURES_DIR.'/7/2026/09/11/1-0.jpg', str_repeat('x', 2048));

    $usage = DiskUsage::snapshot();

    expect($usage->captureBytes)->toBe(2048)
        ->and($usage->captureLabel())->toBe('2 KB')
        ->and($usage->totalBytes)->toBeGreaterThan(0);
});
