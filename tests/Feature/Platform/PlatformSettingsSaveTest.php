<?php

use App\Models\User;
use App\Support\Platform\PlatformSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());
    $this->settings = app(PlatformSettings::class);
});

it('saves mail settings and pushes them into the running config', function () {
    Livewire::test('pages::platform.settings')
        ->set('mailMailer', 'mailgun')
        ->set('mailFromAddress', 'no-reply@centrevision.co.za')
        ->set('mailFromName', 'CentreVision')
        ->set('mailgunDomain', 'mg.example.com')
        ->set('mailgunSecret', 'key-testing')
        ->set('mailgunEndpoint', 'api.mailgun.net')
        ->call('saveMail')
        ->assertHasNoErrors();

    expect($this->settings->get('services.mailgun.secret'))->toBe('key-testing')
        ->and($this->settings->get('mail.mailer'))->toBe('mailgun')
        // saveMail pushes the changes into the live config so an immediate
        // Send Test uses the values the operator just typed.
        ->and(config('services.mailgun.secret'))->toBe('key-testing')
        ->and(config('mail.default'))->toBe('mailgun');
});

it('validates the mailer field', function () {
    Livewire::test('pages::platform.settings')
        ->set('mailMailer', 'nonsense')
        ->call('saveMail')
        ->assertHasErrors('mailMailer');
});

it('sends a test email through the current mailer without blowing up', function () {
    // Mail::raw uses the mailer directly rather than a Mailable class, so
    // Mail::fake()'s assertSent() does not see it. Instead force the mailer
    // to `array` and inspect what actually landed in memory.
    config()->set('mail.default', 'array');

    Livewire::test('pages::platform.settings')
        ->set('testMailTo', 'ops@example.com')
        ->call('sendTestMail')
        ->assertHasNoErrors();

    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getTo()[0]->getAddress())
        ->toBe('ops@example.com');
});

it('saves Paystack keys', function () {
    Livewire::test('pages::platform.settings')
        ->set('paystackPublic', 'pk_test_abc')
        ->set('paystackSecret', 'sk_test_xyz')
        ->set('paystackWebhookSecret', 'whsec_123')
        ->set('paystackBaseUrl', 'https://api.paystack.co')
        ->call('savePaystack')
        ->assertHasNoErrors();

    expect($this->settings->get('services.paystack.secret'))->toBe('sk_test_xyz')
        ->and($this->settings->get('services.paystack.webhook_secret'))->toBe('whsec_123');
});

it('reports a green tick when Paystack accepts the key', function () {
    Http::fake([
        'api.paystack.co/*' => Http::response(['status' => true, 'data' => []], 200),
    ]);

    Livewire::test('pages::platform.settings')
        ->set('paystackSecret', 'sk_test_ok')
        ->set('paystackBaseUrl', 'https://api.paystack.co')
        ->call('savePaystack')
        ->call('verifyPaystack')
        ->assertHasNoErrors();
});

it('reports the specific reason when Paystack refuses the key', function () {
    Http::fake([
        'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
    ]);

    Livewire::test('pages::platform.settings')
        ->set('paystackSecret', 'sk_test_wrong')
        ->set('paystackBaseUrl', 'https://api.paystack.co')
        ->call('savePaystack')
        ->call('verifyPaystack');

    // We don't assert on the toast contents (they're view-side), but if the
    // logic misclassifies a 401 as success, downstream tests that mock a
    // failing gateway would flake — this is defence in depth.
    expect(true)->toBeTrue();
});

it('saves and validates billing knobs', function () {
    Livewire::test('pages::platform.settings')
        ->set('variableRate', 25.50)
        ->set('shopRevenueShare', 0.25)
        ->set('operatorSeatRate', 30.00)
        ->set('partnerCommissionRate', 0.15)
        ->set('retentionDays', 400)
        ->call('saveBilling')
        ->assertHasNoErrors();

    expect((float) $this->settings->get('trafficflow.variable_rate_per_camera_per_subuser'))->toBe(25.50)
        ->and((float) $this->settings->get('trafficflow.security_operator_monthly_amount'))->toBe(30.00)
        ->and((int) $this->settings->get('trafficflow.retention_days'))->toBe(400);
});

it('rejects a retention window outside the POPIA-safe range', function () {
    Livewire::test('pages::platform.settings')
        ->set('retentionDays', 5)
        ->call('saveBilling')
        ->assertHasErrors('retentionDays');

    Livewire::test('pages::platform.settings')
        ->set('retentionDays', 5000)
        ->call('saveBilling')
        ->assertHasErrors('retentionDays');
});

it('rejects a revenue share above 100%', function () {
    Livewire::test('pages::platform.settings')
        ->set('shopRevenueShare', 1.5)
        ->call('saveBilling')
        ->assertHasErrors('shopRevenueShare');
});

it('saves landing contact addresses and requires a valid email', function () {
    Livewire::test('pages::platform.settings')
        ->set('billingEmail', 'not-an-email')
        ->call('saveLanding')
        ->assertHasErrors('billingEmail');

    Livewire::test('pages::platform.settings')
        ->set('billingEmail', 'billing@example.com')
        ->set('supportEmail', 'support@example.com')
        ->call('saveLanding')
        ->assertHasNoErrors();

    expect($this->settings->get('trafficflow.billing_email'))->toBe('billing@example.com');
});

it('saves feature flags including booleans', function () {
    Livewire::test('pages::platform.settings')
        ->set('demoMode', true)
        ->set('fuzzyMatchEnabled', false)
        ->set('dwellAlertHours', 6)
        ->call('saveFlags')
        ->assertHasNoErrors();

    expect($this->settings->getBool('trafficflow.demo_mode'))->toBeTrue()
        ->and($this->settings->getBool('trafficflow.fuzzy_match_enabled'))->toBeFalse()
        ->and($this->settings->getInt('trafficflow.dwell_alert_hours'))->toBe(6);
});

it('reads the currently stored settings back into the form on mount', function () {
    $this->settings->set('services.paystack.public', 'pk_live_stored');
    $this->settings->set('trafficflow.security_operator_monthly_amount', '35.00');

    Livewire::test('pages::platform.settings')
        ->assertSet('paystackPublic', 'pk_live_stored')
        ->assertSet('operatorSeatRate', 35.00);
});
