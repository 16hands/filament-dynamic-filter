<?php

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use SixteenHands\FilamentDynamicFilter\DynamicFilter;

function fixedOptionsSelect(Filter $filter): Select
{
    return collect($filter->getSchemaComponents())->first(fn ($component): bool => $component instanceof Select);
}

it('shows fixed options in the order given, without sorting them', function () {
    $orderedRegions = ['Wellington' => 'Wellington', 'Auckland' => 'Auckland (legacy)', 'Canterbury' => 'Canterbury'];

    $filter = DynamicFilter::relationship(
        name: 'regions',
        column: 'regions.name',
        relationship: 'regions',
        relationshipColumn: 'name',
        multiple: true,
        fixedOptions: fn (): array => $orderedRegions,
    );

    expect(fixedOptionsSelect($filter)->getOptions())->toBe($orderedRegions);
});

it('keeps fixed options in order on single and multiple column filters too', function (string $factoryMethod) {
    $orderedTypes = ['Phone' => 'Phone', 'Email' => 'Email (legacy)', 'Face to face' => 'Face to face'];

    $filter = DynamicFilter::{$factoryMethod}(
        name: 'type_of_contact',
        column: 'type_of_contact',
        queryColumn: 'sessions.type_of_contact',
        fixedOptions: fn (): array => $orderedTypes,
    );

    expect(fixedOptionsSelect($filter)->getOptions())->toBe($orderedTypes);
})->with(['make', 'multiple']);

it('labels a chosen fixed option with its fixed label', function () {
    $fixedOptionFormatter = new ReflectionMethod(DynamicFilter::class, 'fixedOptionFormatter');
    $formatter = $fixedOptionFormatter->invoke(null, fn (): array => ['Auckland' => 'Auckland (legacy)']);

    expect($formatter('Auckland'))->toBe(['Auckland' => 'Auckland (legacy)'])
        ->and($formatter('Nelson'))->toBe(['Nelson' => 'Nelson']);
});
