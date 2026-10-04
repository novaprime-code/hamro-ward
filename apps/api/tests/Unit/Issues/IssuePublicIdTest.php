<?php

declare(strict_types=1);

use App\Modules\Issues\Support\IssuePublicId;

it('prefixes the id with the tenant key and uses only unambiguous characters', function (): void {
    $ids = collect(range(1, 500))->map(fn (): string => IssuePublicId::generate('5f3a9c1e'));

    expect($ids->every(fn (string $id): bool => preg_match('/^5f3a-[0-9A-HJKMNP-TV-Z]{10}$/', $id) === 1))->toBeTrue()
        ->and($ids->unique())->toHaveCount(500)
        ->and(IssuePublicId::tenantPrefix($ids->first()))->toBe('5f3a');
});

it('refuses anything that is not a tenant key', function (): void {
    expect(fn () => IssuePublicId::generate('5F3A9C1E'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IssuePublicId::generate('5f3a'))->toThrow(InvalidArgumentException::class);
});

it('reads back a code as a person typed it', function (string $typed, ?string $expected): void {
    expect(IssuePublicId::normalise($typed))->toBe($expected);
})->with([
    'as printed' => ['5f3a-7K2MQ9XW4R', '5f3a-7K2MQ9XW4R'],
    'lowercase, no dash' => ['5f3a7k2mq9xw4r', '5f3a-7K2MQ9XW4R'],
    'spaces' => [' 5f3a 7K2M Q9XW 4R ', '5f3a-7K2MQ9XW4R'],
    'O and I and L for 0 and 1' => ['5f3a-7K2MQ9XWOI', '5f3a-7K2MQ9XW01'],
    'L for 1' => ['5f3a-LK2MQ9XW4R', '5f3a-1K2MQ9XW4R'],
    'U is never valid' => ['5f3a-7K2MQ9XW4U', null],
    'too short' => ['5f3a-7K2MQ9XW4', null],
    'prefix not hex' => ['zz3a-7K2MQ9XW4R', null],
]);
