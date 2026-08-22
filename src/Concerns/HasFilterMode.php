<?php

namespace SixteenHands\FilamentDynamicFilter\Concerns;

use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Builder;
use SixteenHands\FilamentDynamicFilter\FilterMode;

/**
 * Add to a Livewire table page (alongside a FilterMode::make() filter) to let
 * the user switch active filters between "match all" and "match any".
 *
 * In "any" mode each filter's constraints go into their own orWhere group
 * inside the single where-group Filament wraps around filters. Base-query
 * modifications (e.g. TrashedFilter's soft-delete scope) apply before the
 * group in both modes, and TrashedFilter stays conjunctive.
 *
 * When combined with Archilex AdvancedTables (which also overrides
 * applyFiltersToTableQuery for preset views), resolve the collision with:
 *
 *     use AdvancedTables, HasFilterMode {
 *         HasFilterMode::applyFiltersToTableQuery insteadof AdvancedTables;
 *         AdvancedTables::applyFiltersToTableQuery as protected applyAdvancedTablesFiltersToTableQuery;
 *     }
 *
 * The trait detects the alias and keeps AdvancedTables' behaviour intact.
 */
trait HasFilterMode
{
    protected function applyFiltersToTableQuery(Builder $query, bool $isResolvingRecord = false): Builder
    {
        $mode = FilterMode::modeFromState($this->getTableFilterState(FilterMode::NAME) ?? []);

        if ($mode !== FilterMode::ANY) {
            if (method_exists($this, 'applyAdvancedTablesFiltersToTableQuery')) {
                return $this->applyAdvancedTablesFiltersToTableQuery($query, $isResolvingRecord);
            }

            return parent::applyFiltersToTableQuery($query, $isResolvingRecord);
        }

        // Mirror AdvancedTables' preset-view hook when it is in play.
        if (method_exists($this, 'getActivePresetView') && method_exists($this, 'getCurrentPresetView')) {
            if ($presetView = $this->getActivePresetView() ?? $this->getCurrentPresetView()) {
                $presetView->modifyQuery($query);
            }
        }

        // From here on this mirrors Filament's HasFilters::applyFiltersToTableQuery,
        // swapping the conjunctive apply loop for one orWhere group per filter.
        $table = $this->getTable();

        if ($table->hasDeferredFilters()) {
            $filtersForm = $this->getTableFiltersForm()->statePath('tableFilters');

            $filtersForm->flushCachedAbsoluteStatePaths();
            $filtersForm->clearCachedDefaultChildSchemas();
        }

        try {
            foreach ($table->getFilters() as $filter) {
                $filter->applyToBaseQuery(
                    $query,
                    $this->getTableFilterState($filter->getName()) ?? [],
                );
            }

            // Scope filters remain conjunctive, applied to the outer query so
            // their constraints AND with the disjunctive group below.
            foreach ($table->getFilters() as $filter) {
                if (! $filter instanceof TrashedFilter) {
                    continue;
                }

                if ($isResolvingRecord && $filter->shouldExcludeWhenResolvingRecord()) {
                    continue;
                }

                $filter->apply($query, $this->getTableFilterState($filter->getName()) ?? []);
            }

            return $query->where(function (Builder $query) use ($table, $isResolvingRecord): void {
                foreach ($table->getFilters() as $filter) {
                    if ($filter instanceof TrashedFilter) {
                        continue;
                    }

                    if ($isResolvingRecord && $filter->shouldExcludeWhenResolvingRecord()) {
                        continue;
                    }

                    $state = $this->getTableFilterState($filter->getName()) ?? [];

                    $query->orWhere(function (Builder $group) use ($filter, $state): void {
                        $filter->apply($group, $state);
                    });
                }
            });
        } finally {
            if ($table->hasDeferredFilters()) {
                $filtersForm = $this->getTableFiltersForm()->statePath('tableDeferredFilters');

                $filtersForm->flushCachedAbsoluteStatePaths();
                $filtersForm->clearCachedDefaultChildSchemas();
            }
        }
    }
}
