<?php

declare(strict_types=1);

use App\Modules\Geography\Support\NameNormalizer;

it('normalizes romanized spelling variants', function (string $input, string $expected): void {
    expect(NameNormalizer::normalize($input))->toBe($expected);
})->with([
    'case and spacing' => ['  Namuna   NAGARPALIKA ', 'namuna nagarpalika'],
    'ee → i' => ['Itaharee', 'itahari'],
    'oo → u' => ['Gorkhaa Bazaar', 'gorkha bazar'],
    'w → v' => ['Hawaldar', 'havaldar'],
    'punctuation' => ['Sub-Metropolitan City (SMC)', 'sub metropolitan city smc'],
]);

it('normalizes Devanagari variants', function (): void {
    // nukta removed: ड़ → ड
    expect(NameNormalizer::normalize('बड़ा'))->toBe('बडा')
        // chandrabindu → anusvara
        ->and(NameNormalizer::normalize('गाँउ'))->toBe('गांउ')
        // zero-width joiners removed
        ->and(NameNormalizer::normalize("क्\u{200D}ष"))->toBe('क्ष');
});
