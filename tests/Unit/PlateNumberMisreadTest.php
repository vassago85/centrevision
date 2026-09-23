<?php

use App\Support\PlateNumber;

it('treats one dropped character as the same plate', function () {
    expect(PlateNumber::isProbableMisread('M06KHGP', 'MX06KHGP'))->toBeTrue();
});

it('treats one substituted character as the same plate', function () {
    expect(PlateNumber::isProbableMisread('JD46GP', 'JD45GP'))->toBeTrue();
});

it('rejects a two-character gap', function () {
    expect(PlateNumber::isProbableMisread('JD46NP', 'JD45GP'))->toBeFalse()
        ->and(PlateNumber::isProbableMisread('M0KHGP', 'MX06KHGP'))->toBeFalse();
});

it('does not fuzzy-match an unknown read', function () {
    expect(PlateNumber::isProbableMisread('UNKNOWN', 'UNKNOWX'))->toBeFalse();
});
