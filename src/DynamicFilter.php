<?php

namespace SixteenHands\FilamentDynamicFilter;

use Filament\Forms\Components\Select;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * DynamicFilter - Dynamic table filters for Filament with caching, indicators and panel access control
 */
class DynamicFilter
{
    /**
     * Memoized query hash cache per request.
     *
     * @var array<int, string>
     */
    protected static array $queryHashCache = [];

    /**
     * Create a dynamic filter with caching and proper indicators
     *
     * @param  string  $name  Filter name (e.g., 'packhouse_filter')
     * @param  string  $column  Column path in dot notation (e.g., 'inwards_load.packhouse.packhouse_name')
     * @param  string  $queryColumn  Actual database column for query (e.g., 'packhouses.packhouse_name')
     * @param  string|null  $placeholder  Placeholder text
     * @param  string|null  $label  Custom label
     * @param  bool  $searchable  Whether the select is searchable
     * @param  array|null  $panels  Panel names this filter should be available on (null = all panels)
     * @param  array|null  $optionsMap  Map of raw values to display labels
     * @param  callable|null  $formatOption  Formatter callback: fn($value): array|string|null
     * @param  callable|null  $optionsQuery  Callback to provide a Builder or Collection for options
     * @param  bool  $lazy  Defer loading options until search (requires searchable)
     */
    public static function make(
        string $name,
        string $column,
        string $queryColumn,
        ?string $placeholder = null,
        ?string $label = null,
        bool $searchable = false,
        ?array $panels = null,
        ?array $optionsMap = null,
        ?callable $formatOption = null,
        ?callable $optionsQuery = null,
        bool $lazy = false
    ): Filter {
        if (! self::hasAccess($panels)) {
            return self::createHiddenFilter($name);
        }

        $label = $label ?? ucwords(str_replace(['_', '.'], ' ', $column));
        $placeholder = $placeholder ?? "Select {$label}...";

        $select = Select::make($column)
            ->label($label)
            ->selectablePlaceholder(false)
            ->searchable($searchable)
            ->placeholder($placeholder);

        if ($lazy && $searchable) {
            $select
                ->options([])
                ->getSearchResultsUsing(function (Select $component, HasTable $livewire, ?string $search) use ($column, $queryColumn, $optionsMap, $formatOption, $optionsQuery): array {
                    return self::getSearchResultsOptions($livewire, $column, $queryColumn, $optionsMap, $formatOption, $optionsQuery, $search);
                })
                ->getOptionLabelUsing(function ($value) use ($optionsMap, $formatOption): string {
                    return self::getOptionLabel($value, $optionsMap, $formatOption);
                });
        } else {
            $select->options(function (HasTable $livewire) use ($column, $queryColumn, $optionsMap, $formatOption, $optionsQuery) {
                return self::getCachedOptions($livewire, $column, $queryColumn, $optionsMap, $formatOption, $optionsQuery);
            });
        }

        return Filter::make($name)
            ->schema([
                $select,
            ])
            ->query(function (Builder $query, array $data) use ($column, $queryColumn): Builder {
                $value = data_get($data, $column);

                if (! self::hasValue($value)) {
                    return $query;
                }

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                    $query->whereDate($queryColumn, $value);
                } else {
                    $query->where($queryColumn, $value);
                }

                return $query;
            })
            ->indicateUsing(function (array $data) use ($column, $label, $optionsMap, $formatOption): array {
                $value = data_get($data, $column);

                if (! self::hasValue($value)) {
                    return [];
                }

                $displayValue = self::getOptionLabel($value, $optionsMap, $formatOption);

                return ["{$label}: {$displayValue}"];
            });
    }

    /**
     * Create a multiple selection dynamic filter
     */
    public static function multiple(
        string $name,
        string $column,
        string $queryColumn,
        ?string $placeholder = null,
        ?string $label = null,
        bool $searchable = false,
        ?array $panels = null,
        ?array $optionsMap = null,
        ?callable $formatOption = null,
        ?callable $optionsQuery = null,
        bool $lazy = false
    ): Filter {
        if (! self::hasAccess($panels)) {
            return self::createHiddenFilter($name);
        }

        $label = $label ?? ucwords(str_replace(['_', '.'], ' ', $column));
        $placeholder = $placeholder ?? "Select {$label}...";

        $select = Select::make($column)
            ->label($label)
            ->multiple()
            ->searchable($searchable)
            ->placeholder($placeholder);

        if ($lazy && $searchable) {
            $select
                ->options([])
                ->getSearchResultsUsing(function (Select $component, HasTable $livewire, ?string $search) use ($column, $queryColumn, $optionsMap, $formatOption, $optionsQuery): array {
                    return self::getSearchResultsOptions($livewire, $column, $queryColumn, $optionsMap, $formatOption, $optionsQuery, $search);
                })
                ->getOptionLabelsUsing(function (?array $values) use ($optionsMap, $formatOption): array {
                    $values = self::normalizeValuesArray($values ?? []);
                    $labels = [];

                    foreach ($values as $value) {
                        $labels[$value] = self::getOptionLabel($value, $optionsMap, $formatOption);
                    }

                    return $labels;
                });
        } else {
            $select->options(function (HasTable $livewire) use ($column, $queryColumn, $optionsMap, $formatOption, $optionsQuery) {
                return self::getCachedOptions($livewire, $column, $queryColumn, $optionsMap, $formatOption, $optionsQuery);
            });
        }

        return Filter::make($name)
            ->schema([
                $select,
            ])
            ->query(function (Builder $query, array $data) use ($column, $queryColumn): Builder {
                $value = data_get($data, $column);

                if (! is_array($value)) {
                    return $query;
                }

                $values = self::normalizeValuesArray($value);
                if ($values === []) {
                    return $query;
                }

                $firstValue = $values[0] ?? null;
                $firstValueString = is_scalar($firstValue) ? (string) $firstValue : null;

                if ($firstValueString !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstValueString)) {
                    foreach ($values as $val) {
                        $query->orWhereDate($queryColumn, $val);
                    }
                } else {
                    $query->whereIn($queryColumn, $values);
                }

                return $query;
            })
            ->indicateUsing(function (array $data) use ($column, $label, $optionsMap, $formatOption): array {
                $values = data_get($data, $column);

                if (! $values || ! is_array($values)) {
                    return [];
                }

                $values = self::normalizeValuesArray($values);
                if ($values === []) {
                    return [];
                }

                $displayValues = array_map(
                    fn ($value) => self::getOptionLabel($value, $optionsMap, $formatOption),
                    $values
                );

                return ["{$label}: " . implode(', ', $displayValues)];
            });
    }

    /**
     * Create a relationship-based filter (uses whereHas)
     */
    public static function relationship(
        string $name,
        string $column,
        string $relationship,
        string $relationshipColumn,
        ?string $placeholder = null,
        ?string $label = null,
        bool $searchable = false,
        ?array $panels = null,
        bool $multiple = false,
        ?array $optionsMap = null,
        ?callable $formatOption = null,
        ?callable $optionsQuery = null,
        bool $lazy = false
    ): Filter {
        if (! self::hasAccess($panels)) {
            return self::createHiddenFilter($name);
        }

        $label = $label ?? ucwords(str_replace(['_', '.'], ' ', $column));
        $placeholder = $placeholder ?? "Select {$label}...";

        $optionsQuery ??= function (HasTable $livewire, Builder $query) use ($relationship) {
            $model = $query->getModel();

            if (! method_exists($model, $relationship)) {
                return $query;
            }

            $relationshipQuery = $model->{$relationship}();

            return $relationshipQuery->getRelated()->newQuery();
        };

        $select = Select::make($column)
            ->label($label)
            ->searchable($searchable)
            ->multiple($multiple)
            ->selectablePlaceholder($multiple)
            ->placeholder($placeholder);

        if ($lazy && $searchable) {
            $select
                ->options([])
                ->getSearchResultsUsing(function (Select $component, HasTable $livewire, ?string $search) use ($column, $relationshipColumn, $optionsMap, $formatOption, $optionsQuery): array {
                    return self::getSearchResultsOptions($livewire, $column, $relationshipColumn, $optionsMap, $formatOption, $optionsQuery, $search);
                });

            if ($multiple) {
                $select->getOptionLabelsUsing(function (?array $values) use ($optionsMap, $formatOption): array {
                    $values = self::normalizeValuesArray($values ?? []);
                    $labels = [];

                    foreach ($values as $value) {
                        $labels[$value] = self::getOptionLabel($value, $optionsMap, $formatOption);
                    }

                    return $labels;
                });
            } else {
                $select->getOptionLabelUsing(function ($value) use ($optionsMap, $formatOption): string {
                    return self::getOptionLabel($value, $optionsMap, $formatOption);
                });
            }
        } else {
            $select->options(function (HasTable $livewire) use ($column, $relationshipColumn, $optionsMap, $formatOption, $optionsQuery) {
                return self::getCachedOptions($livewire, $column, $relationshipColumn, $optionsMap, $formatOption, $optionsQuery);
            });
        }

        return Filter::make($name)
            ->schema([
                $select,
            ])
            ->query(function (Builder $query, array $data) use ($column, $relationship, $relationshipColumn): Builder {
                $value = data_get($data, $column);

                if (! self::hasValue($value)) {
                    return $query;
                }

                if (is_array($value)) {
                    $values = self::normalizeValuesArray($value);
                    if ($values === []) {
                        return $query;
                    }
                }

                return $query->whereHas($relationship, function ($q) use ($relationshipColumn, $value) {
                    if (is_array($value)) {
                        $values = self::normalizeValuesArray($value);
                        if ($values !== []) {
                            $q->whereIn($relationshipColumn, $values);
                        }
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                        $q->whereDate($relationshipColumn, $value);
                    } else {
                        $q->where($relationshipColumn, $value);
                    }

                    return $q;
                });
            })
            ->indicateUsing(function (array $data) use ($column, $label, $optionsMap, $formatOption): array {
                $value = data_get($data, $column);

                if (! self::hasValue($value)) {
                    return [];
                }

                if (is_array($value)) {
                    $values = self::normalizeValuesArray($value);
                    if ($values === []) {
                        return [];
                    }

                    $displayValues = array_map(
                        fn ($value) => self::getOptionLabel($value, $optionsMap, $formatOption),
                        $values
                    );

                    return ["{$label}: " . implode(', ', $displayValues)];
                }

                $displayValue = self::getOptionLabel($value, $optionsMap, $formatOption);

                return ["{$label}: {$displayValue}"];
            });
    }

    /**
     * Get cached options for a column with query-based cache key
     */
    protected static function getCachedOptions(
        HasTable $livewire,
        string $column,
        string $queryColumn,
        ?array $optionsMap = null,
        ?callable $formatOption = null,
        ?callable $optionsQuery = null
    ): array {
        [$optionsSource, $query] = self::resolveOptionsSource($livewire, $optionsQuery);

        $cacheTtl = config('filament-dynamic-filter.cache_ttl', 300);
        $maxOptions = config('filament-dynamic-filter.max_options');
        $maxOptions = is_numeric($maxOptions) ? (int) $maxOptions : null;
        $cacheScope = config('filament-dynamic-filter.cache_scope', 'user');
        $cacheKey = null;

        if ($cacheTtl !== null && is_numeric($cacheTtl) && (int) $cacheTtl > 0) {
            $hashSource = $optionsSource instanceof Builder ? $optionsSource : $query;
            if ($hashSource instanceof Builder) {
                $queryHash = self::getQueryHash($hashSource);
                $cacheKey = self::buildCacheKey($cacheScope, $queryHash, $column);
            }
        }

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $values = self::getSearchResults($optionsSource, $query, $column, $queryColumn, $maxOptions);
            $options = self::buildOptions($values, $optionsMap, $formatOption, $maxOptions);

            if ($cacheKey !== null) {
                Cache::put($cacheKey, $options, (int) $cacheTtl);
            }

            return $options;
        } catch (\Throwable $e) {
            \Log::error("DynamicFilter error for column {$column}: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Get search results options for lazy selects.
     */
    protected static function getSearchResultsOptions(
        HasTable $livewire,
        string $column,
        string $queryColumn,
        ?array $optionsMap = null,
        ?callable $formatOption = null,
        ?callable $optionsQuery = null,
        ?string $search = null
    ): array {
        if ($search === null || trim($search) === '') {
            return [];
        }

        [$optionsSource, $query] = self::resolveOptionsSource($livewire, $optionsQuery);

        $maxOptions = config('filament-dynamic-filter.max_options');
        $maxOptions = is_numeric($maxOptions) ? (int) $maxOptions : null;

        try {
            $values = self::getSearchResults($optionsSource, $query, $column, $queryColumn, $maxOptions, $search);

            return self::buildOptions($values, $optionsMap, $formatOption, $maxOptions);
        } catch (\Throwable $e) {
            \Log::error("DynamicFilter search error for column {$column}: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Resolve the base query and option source.
     *
     * @return array{0: mixed, 1: Builder}
     */
    protected static function resolveOptionsSource(HasTable $livewire, ?callable $optionsQuery = null): array
    {
        $table = $livewire->getTable();
        $query = $table->getQuery();
        $optionsSource = $query;

        if ($optionsQuery) {
            $optionsSource = $optionsQuery($livewire, $query);
        }

        return [$optionsSource, $query];
    }

    /**
     * Build formatted options from a collection of values.
     */
    protected static function buildOptions(
        Collection $values,
        ?array $optionsMap = null,
        ?callable $formatOption = null,
        mixed $maxOptions = null
    ): array {
        return $values
            ->filter(fn ($value) => self::hasValue($value))
            ->unique()
            ->sort()
            ->when(is_int($maxOptions) && $maxOptions > 0, fn (Collection $collection) => $collection->take($maxOptions))
            ->mapWithKeys(function ($value) use ($optionsMap, $formatOption) {
                return self::formatOption($value, $optionsMap, $formatOption);
            })
            ->toArray();
    }

    /**
     * Get distinct option values with an optional search filter.
     */
    protected static function getSearchResults(
        mixed $optionsSource,
        ?Builder $fallbackQuery,
        string $column,
        string $queryColumn,
        mixed $maxOptions,
        ?string $search = null
    ): Collection {
        $search = is_string($search) ? trim($search) : null;

        if ($optionsSource instanceof Builder) {
            try {
                return self::pluckDistinctValues($optionsSource, $queryColumn, $maxOptions, $search);
            } catch (\Throwable $e) {
                if ($fallbackQuery instanceof Builder) {
                    return self::filterValuesBySearch(self::pluckFromCollection($fallbackQuery->get(), $column), $search);
                }

                return collect();
            }
        }

        if ($optionsSource instanceof Collection) {
            return self::filterValuesBySearch(self::pluckFromCollection($optionsSource, $column), $search);
        }

        if (is_array($optionsSource)) {
            return self::filterValuesBySearch(collect($optionsSource), $search);
        }

        if ($fallbackQuery instanceof Builder) {
            return self::filterValuesBySearch(self::pluckFromCollection($fallbackQuery->get(), $column), $search);
        }

        return collect();
    }

    /**
     * Apply a simple search filter to a collection of values.
     */
    protected static function filterValuesBySearch(Collection $values, ?string $search): Collection
    {
        if ($search === null || $search === '') {
            return $values;
        }

        $needle = strtolower($search);

        return $values->filter(function ($value) use ($needle): bool {
            if (! self::hasValue($value)) {
                return false;
            }

            $stringValue = self::stringifyValueForSearch($value);

            if ($stringValue === '') {
                return false;
            }

            return str_contains(strtolower($stringValue), $needle);
        });
    }

    /**
     * Normalize values to a string for searching.
     */
    protected static function stringifyValueForSearch(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value)) {
            if ($value instanceof \Illuminate\Support\Carbon) {
                return $value->format('Y-m-d');
            }

            if (enum_exists(get_class($value))) {
                return (string) $value->value;
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
        }

        $encoded = json_encode($value);

        return $encoded !== false ? $encoded : '';
    }

    /**
     * Resolve option values using a Builder or Collection source.
     */
    protected static function resolveOptionValues(
        mixed $optionsSource,
        ?Builder $fallbackQuery,
        string $column,
        string $queryColumn,
        mixed $maxOptions
    ): Collection {
        return self::getSearchResults($optionsSource, $fallbackQuery, $column, $queryColumn, $maxOptions);
    }

    /**
     * Pluck distinct values from a query builder for a specific column.
     */
    protected static function pluckDistinctValues(
        Builder $query,
        string $queryColumn,
        mixed $maxOptions,
        ?string $search = null
    ): Collection
    {
        $distinctQuery = clone $query;
        $distinctQuery->reorder();

        if ($search !== null && $search !== '') {
            $distinctQuery->where($queryColumn, 'like', '%' . $search . '%');
        }

        $distinctQuery->select($queryColumn)
            ->distinct()
            ->orderBy($queryColumn);

        if (is_int($maxOptions) && $maxOptions > 0) {
            $distinctQuery->limit($maxOptions);
        }

        return $distinctQuery->toBase()->pluck($queryColumn);
    }

    /**
     * Pluck values from a collection or return as-is if already scalar values.
     */
    protected static function pluckFromCollection(Collection $collection, string $column): Collection
    {
        if ($collection->isEmpty()) {
            return collect();
        }

        $first = $collection->first();
        if (is_array($first) || is_object($first)) {
            return $collection->pluck($column);
        }

        return $collection;
    }

    /**
     * Build a cache key with the configured scope.
     */
    protected static function buildCacheKey(string $cacheScope, string $queryHash, string $column): ?string
    {
        $scopeKey = self::resolveCacheScopeKey($cacheScope);
        if ($scopeKey === null) {
            return null;
        }

        return 'dynamic_filter_' . $scopeKey . '_' . $queryHash . '_' . str_replace('.', '_', $column);
    }

    /**
     * Resolve cache scope key based on configuration.
     */
    protected static function resolveCacheScopeKey(string $cacheScope): ?string
    {
        return match ($cacheScope) {
            'global' => 'global',
            'tenant' => self::resolveTenantScopeKey(),
            default => (string) (auth()->id() ?? 'guest'),
        };
    }

    /**
     * Resolve tenant scope key via config or callback, if available.
     */
    protected static function resolveTenantScopeKey(): ?string
    {
        $resolver = config('filament-dynamic-filter.tenant_resolver');
        if (is_callable($resolver)) {
            $resolved = $resolver();
            if ($resolved !== null && $resolved !== '') {
                return 'tenant_' . (string) $resolved;
            }
        }

        $tenantKey = config('filament-dynamic-filter.tenant_key');
        if ($tenantKey !== null && $tenantKey !== '') {
            return 'tenant_' . (string) $tenantKey;
        }

        return null;
    }

    /**
     * Memoize the query hash for the current request.
     */
    protected static function getQueryHash(Builder $query): string
    {
        $queryId = spl_object_id($query);

        if (! isset(self::$queryHashCache[$queryId])) {
            self::$queryHashCache[$queryId] = md5($query->toSql() . serialize($query->getBindings()));
        }

        return self::$queryHashCache[$queryId];
    }

    /**
     * Determine whether a value should be considered "set" for filtering.
     */
    protected static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * Normalize array values while keeping false/zero.
     */
    protected static function normalizeValuesArray(array $values): array
    {
        return array_values(array_filter($values, fn ($value) => self::hasValue($value)));
    }

    /**
     * Format a raw option value into a [value => label] pair.
     */
    protected static function formatOption(
        mixed $value,
        ?array $optionsMap = null,
        ?callable $formatOption = null
    ): array {
        if ($formatOption) {
            $formatted = $formatOption($value);
            if ($formatted !== null) {
                return self::normalizeFormattedOption($value, $formatted);
            }
        }

        // Handle date objects
        if (is_object($value) && $value instanceof \Illuminate\Support\Carbon) {
            return [$value->format('Y-m-d') => $value->format('d/m/Y')];
        }

        // Handle enum objects
        if (is_object($value) && enum_exists(get_class($value))) {
            return [$value->value => $value->getLabel()];
        }

        if ($optionsMap !== null && ! is_object($value) && ! is_array($value) && array_key_exists($value, $optionsMap)) {
            return [$value => $optionsMap[$value]];
        }

        if (is_bool($value)) {
            return [$value ? 1 : 0 => $value ? 'True' : 'False'];
        }

        return [$value => $value];
    }

    /**
     * Extract a display label for indicators.
     */
    protected static function getOptionLabel(
        mixed $value,
        ?array $optionsMap = null,
        ?callable $formatOption = null
    ): string {
        $formatted = self::formatOption($value, $optionsMap, $formatOption);
        $label = reset($formatted);

        if (is_bool($label)) {
            return $label ? '1' : '0';
        }

        if (is_object($label) && method_exists($label, '__toString')) {
            return (string) $label;
        }

        if (is_scalar($label) || $label === null) {
            return (string) $label;
        }

        $encoded = json_encode($label);

        return $encoded !== false ? $encoded : '';
    }

    /**
     * Normalize formatter output into a [value => label] pair.
     */
    protected static function normalizeFormattedOption(mixed $value, mixed $formatted): array
    {
        if (is_array($formatted)) {
            return $formatted;
        }

        return [$value => $formatted];
    }

    /**
     * Check if current panel has access to this filter
     */
    protected static function hasAccess(?array $panels): bool
    {
        if ($panels === null) {
            return true;
        }

        $currentPanel = self::getCurrentPanelId();
        if ($currentPanel === null) {
            return true;
        }

        return in_array($currentPanel, $panels, true);
    }

    /**
     * Best-effort lookup for current Filament panel id (optional dependency).
     */
    protected static function getCurrentPanelId(): ?string
    {
        $filamentFacade = 'Filament\\Facades\\Filament';
        if (! class_exists($filamentFacade)) {
            return null;
        }

        try {
            return $filamentFacade::getCurrentPanel()?->getId();
        } catch (\Exception $e) {
            \Log::warning('Could not determine current Filament panel: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Create a hidden filter that doesn't show up in the UI
     */
    protected static function createHiddenFilter(string $name): Filter
    {
        return Filter::make($name)
            ->schema([])
            ->query(fn (Builder $query, array $data): Builder => $query)
            ->hidden();
    }

    /**
     * Clear cache for dynamic filters
     */
    public static function clearCache(?int $userId = null): void
    {
        $pattern = 'dynamic_filter_' . ($userId ?? auth()->id()) . '_*';
        // Implementation depends on cache driver
        // For Redis: Cache::forget() with pattern matching
        // For file/database: would need custom logic
    }
}
