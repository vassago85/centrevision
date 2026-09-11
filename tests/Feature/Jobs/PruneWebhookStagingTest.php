<?php

use App\Http\Controllers\HikvisionWebhookController;
use App\Jobs\ProcessHikvisionWebhook;
use App\Jobs\PruneWebhookStaging;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('deletes quarantine files older than the diagnosis window', function () {
    Date::setTestNow('2026-09-11 10:00:00');

    $stale = ProcessHikvisionWebhook::QUARANTINE_DIR.'/12/old.bin';
    $fresh = ProcessHikvisionWebhook::QUARANTINE_DIR.'/12/new.bin';

    Storage::disk('local')->put($stale, 'unparseable');
    Storage::disk('local')->put($fresh, 'unparseable');
    touch(Storage::disk('local')->path($stale), now()->subDays(10)->timestamp);

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
