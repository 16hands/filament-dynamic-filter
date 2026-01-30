<?php

namespace SixteenHands\FilamentDynamicFilter;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Filament\Facades\Filament;

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
     */
    public static function make(
        string $name,
        string $column,
        string $queryColumn,
        ?string $placeholder = null,
        ?string $label = null,
        bool $searchable = false,
        ?array $panels = null
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
                    ->options(function (HasTable $livewire) use ($column) {
                        return self::getCachedOptions($livewire, $column);
                    })
                    ->searchable($searchable)
                    ->placeholder($placeholder),
            ])
            ->query(function (Builder $query, array $data) use ($column, $queryColumn): Builder {
                $value = data_get($data, $column);

                return $query->when(
                    $value,
                    function (Builder $query) use ($queryColumn, $value) {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $query->whereDate($queryColumn, $value);
                        } else {
                            $query->where($queryColumn, $value);
                        }

                        return $query;
                    }
                );
            })
            ->indicateUsing(function (array $data) use ($column, $label): array {
                $value = data_get($data, $column);

                if (!$value) {
                    return [];
                }

                return ["{$label}: {$value}"];
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
        ?array $panels = null
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
                    ->options(function (HasTable $livewire) use ($column) {
                        return self::getCachedOptions($livewire, $column);
                    })
                    ->multiple()
                    ->searchable($searchable)
                    ->placeholder($placeholder),
            ])
            ->query(function (Builder $query, array $data) use ($column, $queryColumn): Builder {
                $value = data_get($data, $column);

                return $query->when(
                    $value && is_array($value),
                    function (Builder $query) use ($queryColumn, $value) {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', collect($value)->first())) {
                            foreach ($value as $val) {
                                $query->orWhereDate($queryColumn, $val);
                            }
                        } else {
                            $query->whereIn($queryColumn, $value);
                        }

                        return $query;
                    }
                );
            })
            ->indicateUsing(function (array $data) use ($column, $label): array {
                $values = data_get($data, $column);

                if (!$values || !is_array($values)) {
                    return [];
                }

                return ["{$label}: ".implode(', ', $values)];
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
        bool $multiple = false
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
                    ->options(function (HasTable $livewire) use ($column) {
                        return self::getCachedOptions($livewire, $column);
                    })
                    ->searchable($searchable)
                    ->multiple($multiple)
                    ->placeholder($placeholder),
            ])
            ->query(function (Builder $query, array $data) use ($column, $relationship, $relationshipColumn): Builder {
                $value = data_get($data, $column);

                return $query->when(
                    $value,
                    fn (Builder $query) => $query->whereHas($relationship, function ($q) use ($relationshipColumn, $value) {
                        if (is_array($value)) {
                            $q->whereIn($relationshipColumn, $value);
                        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $q->whereDate($relationshipColumn, $value);
                        } else {
                            $q->where($relationshipColumn, $value);
                        }

                        return $q;
                    })
                );
            })
            ->indicateUsing(function (array $data) use ($column, $label): array {
                $value = data_get($data, $column);

                if (!$value) {
                    return [];
                }

                if (is_array($value)) {
                    return ["{$label}: ".implode(', ', $value)];
                }

                return ["{$label}: {$value}"];
            });
    }

    /**
     * Get cached options for a column with query-based cache key
     */
    protected static function getCachedOptions(HasTable $livewire, string $column): array
    {
        $table = $livewire->getTable();
        $query = $table->getQuery();
        $queryHash = md5($query?->toSql().serialize($query?->getBindings()));

        $filterCacheKey = 'dynamic_filter_'.auth()->id().'_'.$queryHash.'_'.str_replace('.', '_', $column);
        $resultsCacheKey = 'dynamic_filter_'.auth()->id().'_'.$queryHash;

        return Cache::remember($filterCacheKey, 300, function () use ($query, $column, $resultsCacheKey) {
            try {
                $allRecords = Cache::remember($resultsCacheKey, 300, function () use ($query) {
                    return $query->get();
                });

                return $allRecords->pluck($column)
                    ->unique()
                    ->filter()
                    ->sort()
                    ->mapWithKeys(function ($value) {
                        // Handle date objects
                        if (is_object($value) && $value instanceof \Illuminate\Support\Carbon) {
                            return [$value->format('Y-m-d') => $value->format('d/m/Y')];
                        }
                        // Handle enum objects
                        if (is_object($value) && enum_exists(get_class($value))) {
                            return [$value->value => $value->getLabel()];
                        }

                        return [$value => $value];
                    })
                    ->toArray();
            } catch (\Exception $e) {
                \Log::error("DynamicFilter error for column {$column}: ".$e->getMessage());

                return [];
            }
        });
    }

    /**
     * Check if current panel has access to this filter
     */
    protected static function hasAccess(?array $panels): bool
    {
        if ($panels === null) {
            return true;
        }

        try {
            $currentPanel = Filament::getCurrentPanel()?->getId();

            return $currentPanel && in_array($currentPanel, $panels);
        } catch (\Exception $e) {
            \Log::warning('Could not determine current Filament panel: '.$e->getMessage());

            return true;
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
