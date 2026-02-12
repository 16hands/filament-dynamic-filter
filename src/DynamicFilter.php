<?php

namespace SixteenHands\FilamentDynamicFilter;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * DynamicFilter - Dynamic table filters for Filament with caching, indicators and panel access control
 */
class DynamicFilter
{
    /**
     * Create a dynamic filter with caching and proper indicators
     *
     * @param string $name Filter name (e.g., 'packhouse_filter')
     * @param string $column Column path in dot notation (e.g., 'inwards_load.packhouse.packhouse_name')
     * @param string $queryColumn Actual database column for query (e.g., 'packhouses.packhouse_name')
     * @param string|null $placeholder Placeholder text
     * @param string|null $label Custom label
     * @param bool $searchable Whether the select is searchable
     * @param array|null $panels Panel names this filter should be available on (null = all panels)
     * @param array|null $optionsMap Map of raw values to display labels
     * @param callable|null $formatOption Formatter callback: fn($value): array|string|null
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
        ?callable $formatOption = null
    ): Filter {
        if (!self::hasAccess($panels)) {
            return self::createHiddenFilter($name);
        }

        $label = $label ?? ucwords(str_replace(['_', '.'], ' ', $column));
        $placeholder = $placeholder ?? "Select {$label}...";

        return Filter::make($name)
            ->schema([
                Select::make($column)
                    ->label($label)
                    ->options(function (HasTable $livewire) use ($column, $optionsMap, $formatOption) {
                        return self::getCachedOptions($livewire, $column, $optionsMap, $formatOption);
                    })
                    ->searchable($searchable)
                    ->placeholder($placeholder),
            ])
            ->query(function (Builder $query, array $data) use ($column, $queryColumn): Builder {
                $value = data_get($data, $column);

                if (!self::hasValue($value)) {
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

                if (!self::hasValue($value)) {
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
        ?callable $formatOption = null
    ): Filter {
        if (!self::hasAccess($panels)) {
            return self::createHiddenFilter($name);
        }

        $label = $label ?? ucwords(str_replace(['_', '.'], ' ', $column));
        $placeholder = $placeholder ?? "Select {$label}...";

        return Filter::make($name)
            ->schema([
                Select::make($column)
                    ->label($label)
                    ->options(function (HasTable $livewire) use ($column, $optionsMap, $formatOption) {
                        return self::getCachedOptions($livewire, $column, $optionsMap, $formatOption);
                    })
                    ->multiple()
                    ->searchable($searchable)
                    ->placeholder($placeholder),
            ])
            ->query(function (Builder $query, array $data) use ($column, $queryColumn): Builder {
                $value = data_get($data, $column);

                if (!is_array($value)) {
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

                if (!$values || !is_array($values)) {
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

                return ["{$label}: ".implode(', ', $displayValues)];
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
        ?callable $formatOption = null
    ): Filter {
        if (!self::hasAccess($panels)) {
            return self::createHiddenFilter($name);
        }

        $label = $label ?? ucwords(str_replace(['_', '.'], ' ', $column));
        $placeholder = $placeholder ?? "Select {$label}...";

        return Filter::make($name)
            ->schema([
                Select::make($column)
                    ->label($label)
                    ->options(function (HasTable $livewire) use ($column, $optionsMap, $formatOption) {
                        return self::getCachedOptions($livewire, $column, $optionsMap, $formatOption);
                    })
                    ->searchable($searchable)
                    ->multiple($multiple)
                    ->placeholder($placeholder),
            ])
            ->query(function (Builder $query, array $data) use ($column, $relationship, $relationshipColumn): Builder {
                $value = data_get($data, $column);

                if (!self::hasValue($value)) {
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

                if (!self::hasValue($value)) {
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

                    return ["{$label}: ".implode(', ', $displayValues)];
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
        ?array $optionsMap = null,
        ?callable $formatOption = null
    ): array
    {
        $table = $livewire->getTable();
        $query = $table->getQuery();
        $queryHash = md5($query?->toSql().serialize($query?->getBindings()));

        $filterCacheKey = 'dynamic_filter_'.auth()->id().'_'.$queryHash.'_'.str_replace('.', '_', $column);
        $resultsCacheKey = 'dynamic_filter_'.auth()->id().'_'.$queryHash;

        return Cache::remember($filterCacheKey, 300, function () use ($query, $column, $resultsCacheKey, $optionsMap, $formatOption) {
            try {
                $allRecords = Cache::remember($resultsCacheKey, 300, function () use ($query) {
                    return $query->get();
                });

                return $allRecords->pluck($column)
                    ->unique()
                    ->filter(fn ($value) => self::hasValue($value))
                    ->sort()
                    ->mapWithKeys(function ($value) use ($optionsMap, $formatOption) {
                        return self::formatOption($value, $optionsMap, $formatOption);
                    })
                    ->toArray();
            } catch (\Exception $e) {
                \Log::error("DynamicFilter error for column {$column}: ".$e->getMessage());

                return [];
            }
        });
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

        if ($optionsMap !== null && !is_object($value) && !is_array($value) && array_key_exists($value, $optionsMap)) {
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
        if (!class_exists($filamentFacade)) {
            return null;
        }

        try {
            return $filamentFacade::getCurrentPanel()?->getId();
        } catch (\Exception $e) {
            \Log::warning('Could not determine current Filament panel: '.$e->getMessage());

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
        $pattern = 'dynamic_filter_'.($userId ?? auth()->id()).'_*';
        // Implementation depends on cache driver
        // For Redis: Cache::forget() with pattern matching
        // For file/database: would need custom logic
    }
}
