<?php

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\User;

it('records who signed off the decision and when', function () {
    $reviewer = User::factory()->platformAdmin()->create();
    $approval = Approval::factory()->forOwnerRegistration()->create();

    $approval->approve($reviewer, 'Looked legit.');

    expect($approval->refresh())
        ->status->toBe(ApprovalStatus::Approved)
        ->and($approval->reviewed_by_user_id)->toBe($reviewer->id)
        ->and($approval->reviewed_at)->not->toBeNull()
        ->and($approval->review_note)->toBe('Looked legit.');
});

it('records rejections with the mandatory note', function () {
    $reviewer = User::factory()->platformAdmin()->create();
    $approval = Approval::factory()->forOwnerRegistration()->create();

    $approval->reject($reviewer, 'Company does not exist.');

    expect($approval->refresh())
        ->status->toBe(ApprovalStatus::Rejected)
        ->and($approval->review_note)->toBe('Company does not exist.');
});

it('refuses to sign off an already-decided approval', function () {
    $reviewer = User::factory()->platformAdmin()->create();
    $approval = Approval::factory()->forOwnerRegistration()->approved($reviewer)->create();

    // A double-sign attempt is treated as programmer error so the audit
    // trail never accidentally overwrites an existing decision.
    expect(fn () => $approval->approve($reviewer))->toThrow(InvalidArgumentException::class);
    expect(fn () => $approval->reject($reviewer, 'changed mind'))->toThrow(InvalidArgumentException::class);
});

it('filters to pending via the scope', function () {
    Approval::factory()->forOwnerRegistration()->count(2)->create();
    Approval::factory()->forOwnerRegistration()->approved()->create();
    Approval::factory()->forOwnerRegistration()->rejected()->create();

    expect(Approval::pending()->count())->toBe(2);
});
