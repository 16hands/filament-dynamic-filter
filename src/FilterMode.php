<?php

namespace SixteenHands\FilamentDynamicFilter;

use Filament\Forms\Components\ToggleButtons;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * A pseudo-filter holding the user's choice of how active filters combine:
 * "all" (conjunctive, Filament's default) or "any" (disjunctive).
 *
 * The filter itself never touches the query — pair it with the
 * Concerns\HasFilterMode trait on the table's Livewire page, which reads
 * this filter's state and applies the filters accordingly.
 */
class FilterMode
{
    public const NAME = 'filter_mode';

    public const ALL = 'all';

    public const ANY = 'any';

    public static function make(): Filter
    {
        return Filter::make(self::NAME)
            ->label('Filter matching')
            ->form([
                ToggleButtons::make('mode')
                    ->label('Filter matching')
                    ->options([
                        self::ALL => 'Match all',
                        self::ANY => 'Match any',
                    ])
                    ->default(self::ALL)
                    ->grouped(),
            ])
            ->query(fn (Builder $query): Builder => $query)
            ->indicateUsing(fn (array $data): ?string => self::modeFromState($data) === self::ANY
                ? 'Matching any filter'
                : null);
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function modeFromState(?array $state): string
    {
        return ($state['mode'] ?? self::ALL) === self::ANY ? self::ANY : self::ALL;
    }
}
