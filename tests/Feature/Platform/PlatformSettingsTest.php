<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Cache the settings bag by default; every test either wants a fresh
    // read or is explicitly asserting cache behaviour, so we start clean.
    Cache::flush();
    $this->settings = app(PlatformSettings::class);
});

it('falls back to the config repository when no row exists', function () {
    config()->set('services.mailgun.secret', 'from-env');

    expect($this->settings->get('services.mailgun.secret', 'services.mailgun.secret'))
        ->toBe('from-env');
});

it('reads a stored override in preference to config', function () {
    $this->settings->set('services.mailgun.secret', 'from-ui');
    config()->set('services.mailgun.secret', 'from-env');

    expect($this->settings->get('services.mailgun.secret', 'services.mailgun.secret'))
        ->toBe('from-ui');
});

it('returns the explicit default when neither DB nor config has a value', function () {
    expect($this->settings->get('does.not.exist', 'also.missing', 'fallback'))
        ->toBe('fallback');
});

it('treats an empty stored value as absent and falls back to config', function () {
    $this->settings->set('services.mailgun.secret', '');
    config()->set('services.mailgun.secret', 'from-env');

    // An admin clearing a field should mean "use the .env default", not
    // "override with an empty string" — the latter would break the mailer.
    expect($this->settings->get('services.mailgun.secret', 'services.mailgun.secret'))
        ->toBe('from-env');
});

it('encrypts stored values so a raw DB read cannot see them', function () {
    $this->settings->set('services.paystack.secret', 'sk_live_supersecret');

    $raw = \Illuminate\Support\Facades\DB::table('platform_settings')
        ->where('key', 'services.paystack.secret')
        ->value('value');

    expect($raw)->not->toBe('sk_live_supersecret')
        ->and($this->settings->get('services.paystack.secret'))
        ->toBe('sk_live_supersecret');
});

it('stamps the acting user on writes', function () {
    $actor = User::factory()->platformAdmin()->create();

    $this->settings->set('services.paystack.secret', 'sk_test', $actor);

    expect(PlatformSetting::query()->where('key', 'services.paystack.secret')->sole()->updated_by_user_id)
        ->toBe($actor->id);
});

it('coerces boolean and numeric helpers cleanly', function () {
    $this->settings->set('flag.enabled', '1');
    $this->settings->set('rate.per', '19.50');
    $this->settings->set('days.retention', '400');

    expect($this->settings->getBool('flag.enabled'))->toBeTrue()
        ->and($this->settings->getFloat('rate.per'))->toBe(19.50)
        ->and($this->settings->getInt('days.retention'))->toBe(400);
});

it('flushes its cache on write so a follow-up read sees the new value', function () {
    $this->settings->set('key', 'v1');
    expect($this->settings->get('key'))->toBe('v1');

    // Directly modify the DB, prove the service still returns the cached
    // value, then flush and prove it re-reads. Uses Crypt::encryptString
    // rather than encrypt() so no PHP serialization prefix leaks through
    // the model's `encrypted` cast on read.
    \Illuminate\Support\Facades\DB::table('platform_settings')
        ->where('key', 'key')
        ->update(['value' => \Illuminate\Support\Facades\Crypt::encryptString('v2')]);

    // A fresh service instance would miss the memo but the cache still
    // holds the old bag, so build a new service to isolate the layer.
    $fresh = new PlatformSettings;
    expect($fresh->get('key'))->toBe('v1');

    $fresh->flush();
    expect($fresh->get('key'))->toBe('v2');
});
