<?php

use App\Http\Controllers\HikvisionWebhookController;
use App\Jobs\ProcessHikvisionWebhook;
use App\Jobs\PruneWebhookStaging;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('wipes leftover quarantine files', function () {
    $quarantine = ProcessHikvisionWebhook::QUARANTINE_DIR.'/12/old.bin';
    Storage::disk('local')->put($quarantine, 'unparseable');

    PruneWebhookStaging::dispatchSync();

    expect(Storage::disk('local')->exists($quarantine))->toBeFalse();
});

it('deletes plate-capture JPEGs older than a day and keeps newer ones', function () {
    Date::setTestNow('2026-09-11 10:00:00');

    $stale = ProcessHikvisionWebhook::CAPTURES_DIR.'/12/2026/09/10/99-0.jpg';
    $fresh = ProcessHikvisionWebhook::CAPTURES_DIR.'/12/2026/09/11/100-0.jpg';

    Storage::disk('local')->put($stale, 'old-jpeg');
    Storage::disk('local')->put($fresh, 'new-jpeg');
    touch(Storage::disk('local')->path($stale), now()->subHours(25)->timestamp);

    PruneWebhookStaging::dispatchSync();

    expect(Storage::disk('local')->exists($stale))->toBeFalse()
        ->and(Storage::disk('local')->exists($fresh))->toBeTrue();
});

it('deletes inbox files older than the stuck-queue window', function () {
    Date::setTestNow('2026-09-11 10:00:00');

    $stale = HikvisionWebhookController::INBOX_DIR.'/12/old.bin';
    $fresh = HikvisionWebhookController::INBOX_DIR.'/12/new.bin';

    Storage::disk('local')->put($stale, 'pending');
    Storage::disk('local')->put($fresh, 'pending');
    touch(Storage::disk('local')->path($stale), now()->subHours(30)->timestamp);

    PruneWebhookStaging::dispatchSync();

    expect(Storage::disk('local')->exists($stale))->toBeFalse()
        ->and(Storage::disk('local')->exists($fresh))->toBeTrue();
});
