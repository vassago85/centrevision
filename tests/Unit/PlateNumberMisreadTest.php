<?php

use App\Support\PlateNumber;

it('treats one dropped character as the same plate', function () {
    expect(PlateNumber::isProbableMisread('M06KHGP', 'MX06KHGP'))->toBeTrue();
});

it('treats one substituted character as the same plate', function () {
    expect(PlateNumber::isProbableMisread('JD46GP', 'JD45GP'))->toBeTrue();
});

it('treats two substituted or dropped characters as the same plate', function () {
    expect(PlateNumber::isProbableMisread('JD46NP', 'JD45GP'))->toBeTrue()
        ->and(PlateNumber::isProbableMisread('M0KHGP', 'MX06KHGP'))->toBeTrue();
});

it('rejects a three-character gap', function () {
    expect(PlateNumber::isProbableMisread('JD99NP', 'JD45GP'))->toBeFalse()
        ->and(PlateNumber::isProbableMisread('MKHGP', 'MX06KHGP'))->toBeFalse();
});

it('does not fuzzy-match an unknown read', function () {
    expect(PlateNumber::isProbableMisread('UNKNOWN', 'UNKNOWX'))->toBeFalse();
});

it('prefers an exact plate, then one character, then two', function () {
    expect(PlateNumber::closestPlate('JD45GP', ['JD46GP', 'JD45GP', 'JD99NP']))->toBe('JD45GP')
        ->and(PlateNumber::closestPlate('JD46GP', ['JD47NP', 'JD45GP']))->toBe('JD45GP')
        ->and(PlateNumber::closestPlate('JD46NP', ['JD45GP']))->toBe('JD45GP');
});

it('does not pick a two-character plate when two one-character plates exist', function () {
    expect(PlateNumber::closestPlate('JD46GP', ['JD45GP', 'JD47GP', 'JD99NP']))->toBeNull();
});
