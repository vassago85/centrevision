<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\OrganizationType;
use App\Models\Approval;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

it('leaves a new owner organization unapproved and queues an Approval row', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Thandi',
        'email' => 'thandi@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'organization_name' => 'Thandi Malls',
    ]);

    $org = Organization::where('name', 'Thandi Malls')->sole();

    expect($org->type)->toBe(OrganizationType::Owner)
        ->and($org->approved_at)->toBeNull()
        ->and($org->isApproved())->toBeFalse();

    $approval = Approval::sole();

    expect($approval->kind)->toBe(ApprovalKind::OwnerRegistration)
        ->and($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->subject_id)->toBe($org->id)
        ->and($approval->requested_by_user_id)->toBe($user->id)
        ->and($approval->payload['organization_name'])->toBe('Thandi Malls');
});

it('redirects a pending owner from any tenant route to the waiting page', function () {
    $org = Organization::factory()->owner()->pendingApproval()->create();
    $user = User::factory()->ownerAdmin($org)->create();

    // Verify the middleware order first: EnsureTenantContext short-circuits
    // to registration.pending before any of the normal routing kicks in.
    $this->actingAs($user);

    $this->get(route('overview'))->assertRedirect(route('registration.pending'));
});

it('lets a pending owner load the waiting page itself without redirect looping', function () {
    $org = Organization::factory()->owner()->pendingApproval()->create();
    $user = User::factory()->ownerAdmin($org)->create();

    $this->actingAs($user);

    $this->get(route('registration.pending'))->assertOk();
});

it('unblocks an owner as soon as their organization is approved', function () {
    $org = Organization::factory()->owner()->pendingApproval()->create();
    App\Models\Site::factory()->for_($org)->create();
    $user = User::factory()->ownerAdmin($org)->create();

    // Approve the org so the middleware no longer bounces them.
    $org->forceFill(['approved_at' => now()])->save();

    $this->actingAs($user);

    // A subscription is required by the `subscribed` middleware — factory
    // default is Active, so simply having one is enough to reach the page.
    App\Models\SiteSubscription::factory()->for(App\Models\Site::query()->first())->create();

    $this->get(route('overview'))->assertOk();
});

it('lets a platform admin approve a pending owner registration', function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());

    $org = Organization::factory()->owner()->pendingApproval()->create();
    $approval = Approval::factory()->forOwnerRegistration($org)->create();

    Livewire::test('pages::platform.approvals')
        ->call('openReview', $approval->id)
        ->set('note', 'Confirmed by phone.')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSet('reviewingId', null);

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->fresh()->review_note)->toBe('Confirmed by phone.')
        ->and($org->fresh()->approved_at)->not->toBeNull()
        ->and($org->fresh()->approved_by_user_id)->toBe(auth()->id());
});

it('requires a note when rejecting a registration', function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());

    $approval = Approval::factory()->forOwnerRegistration()->create();

    Livewire::test('pages::platform.approvals')
        ->call('openReview', $approval->id)
        ->call('reject')
        ->assertHasErrors('note');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('rejects a registration with the reason attached', function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());

    $org = Organization::factory()->owner()->pendingApproval()->create();
    $approval = Approval::factory()->forOwnerRegistration($org)->create();

    Livewire::test('pages::platform.approvals')
        ->call('openReview', $approval->id)
        ->set('note', 'This looks like a fake company.')
        ->call('reject')
        ->assertHasNoErrors();

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Rejected)
        // The org is intentionally NOT approved — the rejection stands and
        // the applicant sees the reason on their waiting page.
        ->and($org->fresh()->approved_at)->toBeNull();
});

it('does not double-approve if the reviewer clicks twice', function () {
    Livewire::actingAs(User::factory()->platformAdmin()->create());

    $approval = Approval::factory()->forOwnerRegistration()->create();

    $component = Livewire::test('pages::platform.approvals')
        ->call('openReview', $approval->id)
        ->call('approve');

    // Second click after resolution should be a 404, not an overwrite.
    $component->call('openReview', $approval->id)
        ->call('approve')
        ->assertStatus(404);
});
