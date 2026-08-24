<?php

use App\Support\Utf8;

it('leaves valid utf-8 alone', function () {
    expect(Utf8::clean('Menlyn Park'))->toBe('Menlyn Park');
});

it('repairs a windows-1252 byte so json_encode succeeds', function () {
    $dirty = "Mall \x80 A";

    expect(mb_check_encoding($dirty, 'UTF-8'))->toBeFalse();

    $clean = Utf8::clean($dirty);

    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue()
        ->and(json_encode($clean))->not->toBeFalse();
});
