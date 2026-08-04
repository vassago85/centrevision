<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create();
    $this->operator = User::factory()->securityOperator($this->owner)->create();
});

it('flags the role via helpers on both the enum and the user', function () {
    expect($this->operator->isSecurityOperator())->toBeTrue()
        ->and($this->operator->isOwnerAdmin())->toBeFalse()
        ->and($this->operator->isShopUser())->toBeFalse()
        ->and($this->operator->isPlatformAdmin())->toBeFalse();

    expect(UserRole::SecurityOperator->isSecurityRole())->toBeTrue()
        ->and(UserRole::SecurityOperator->isOwnerRole())->toBeFalse()
        ->and(UserRole::SecurityOperator->isShopRole())->toBeFalse()
        ->and(UserRole::SecurityOperator->label())->toBe('Security operator');
});

it('grants an operator the security & watchlist permissions but nothing else', function () {
    expect($this->operator->can('view aggregate analytics'))->toBeTrue()
        ->and($this->operator->can('view plate level data'))->toBeTrue()
        ->and($this->operator->can('view security alerts'))->toBeTrue()
        ->and($this->operator->can('manage watchlist'))->toBeTrue();

    // Explicitly denied: anything the owner uses to spend money or reshape
    // the site is off-limits so a hired guard cannot billed, cameras added,
    // or shops onboarded from their seat.
    expect($this->operator->can('manage cameras'))->toBeFalse()
        ->and($this->operator->can('manage shops'))->toBeFalse()
        ->and($this->operator->can('manage billing'))->toBeFalse()
        ->and($this->operator->can('manage site settings'))->toBeFalse()
        ->and($this->operator->can('manage users'))->toBeFalse();
});

it('also grants owners the split-out manage-watchlist permission so nothing regresses', function () {
    $owner = User::factory()->ownerAdmin($this->owner)->create();

    expect($owner->can('manage watchlist'))->toBeTrue();
});

it('lets the site policy manageWatchlist through for an operator and blocks it for a shop', function () {
    // SitePolicy consults the Tenancy singleton for accessible sites, so
    // each assertion must be made as the acting user.
    actingAsTenant($this->operator);

    expect($this->operator->can('manageWatchlist', $this->site))->toBeTrue()
        ->and($this->operator->can('viewSecurity', $this->site))->toBeTrue()
        ->and($this->operator->can('manageCameras', $this->site))->toBeFalse();

    $shopUser = User::factory()->shopAdmin()->create();
    actingAsTenant($shopUser);

    // Shop users see aggregate numbers only — no security or watchlist rights.
    expect($shopUser->can('manageWatchlist', $this->site))->toBeFalse()
        ->and($shopUser->can('viewSecurity', $this->site))->toBeFalse();
});
