<?php

use SixteenHands\FilamentDynamicFilter\DynamicFilter;

/**
 * @param  array<int, mixed>  $values
 * @return array<int, mixed>
 */
function filteredOptionValues(array $values, ?string $search): array
{
    $filterValuesBySearch = new ReflectionMethod(DynamicFilter::class, 'filterValuesBySearch');

    return $filterValuesBySearch->invoke(null, collect($values), $search)->values()->all();
}

it('finds a macron option from a plain search term', function () {
    $iwi = ['Ngāi Tahu', 'Waikato', 'Te Whānau-ā-Apanui'];

    expect(filteredOptionValues($iwi, 'ngai'))->toBe(['Ngāi Tahu'])
        ->and(filteredOptionValues($iwi, 'whanau'))->toBe(['Te Whānau-ā-Apanui']);
});

it('finds a plain option from a macron search term', function () {
    expect(filteredOptionValues(['Ngai Tahu', 'Waikato'], 'Ngāi'))->toBe(['Ngai Tahu'])
        ->and(filteredOptionValues(['Maori Models of Health'], 'Māori'))->toBe(['Maori Models of Health']);
});

it('ignores case as well as macrons', function () {
    expect(filteredOptionValues(['ngāi tahu'], 'NGAI'))->toBe(['ngāi tahu'])
        ->and(filteredOptionValues(['NGAI TAHU'], 'ngāi'))->toBe(['NGAI TAHU']);
});

it('still excludes options that do not match', function () {
    expect(filteredOptionValues(['Ngāi Tahu', 'Waikato'], 'tainui'))->toBe([]);
});

it('returns every option when there is no search term', function () {
    expect(filteredOptionValues(['Ngāi Tahu', 'Waikato'], null))->toBe(['Ngāi Tahu', 'Waikato'])
        ->and(filteredOptionValues(['Ngāi Tahu', 'Waikato'], ''))->toBe(['Ngāi Tahu', 'Waikato']);
});

it('drops blank options rather than matching them', function () {
    expect(filteredOptionValues(['Ngāi Tahu', null, ''], 'ngai'))->toBe(['Ngāi Tahu']);
});
