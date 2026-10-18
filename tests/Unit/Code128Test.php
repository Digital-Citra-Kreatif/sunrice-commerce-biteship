<?php

declare(strict_types=1);

use Sunrice\CommerceBiteship\Label\Code128;

it('encodes set B with its checksum, start and stop', function () {
    // "A": start B (104), A (33), checksum (104 + 33) % 103 = 34, stop.
    expect(Code128::widths('A'))->toBe('211214'.'111323'.'131123'.'2331112');
});

it('draws an SVG with a bar for every other width', function () {
    $svg = Code128::svg('JNE0001');

    expect($svg)->toStartWith('<svg')
        ->and(substr_count($svg, '<rect') - 1)->toBe(intdiv(strlen(Code128::widths('JNE0001')) + 1, 2));
});
