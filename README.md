# Filament Dynamic Filter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sixteenhands/filament-dynamic-filter.svg?style=flat-square)](https://packagist.org/packages/sixteenhands/filament-dynamic-filter)
[![Total Downloads](https://img.shields.io/packagist/dt/sixteenhands/filament-dynamic-filter.svg?style=flat-square)](https://packagist.org/packages/sixteenhands/filament-dynamic-filter)

Dynamic select filters for Filament tables that use the current table's records as options.

## The Problem

If you've used DataTables before, you'll remember the column header filters that automatically populated with values from the table data. Filament's built-in `SelectFilter` requires you to manually define options or query them separately from an entire table/model.

This becomes a real pain with relationship managers. Say you have an `Order` resource with a `LineItems` relation manager. You want to filter line items by product name, but only show products that actually exist in *this order's* line items — not every product in the database.

This package gives you filters that pull their options directly from the current table query, respecting any existing filters or scopes already applied.

## Installation

```bash
composer require sixteenhands/filament-dynamic-filter
```

## Usage

```php
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use SixteenHands\FilamentDynamicFilter\DynamicFilter;

public function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('packhouse.packhouse_name'),
            TextColumn::make('grade'),
            TextColumn::make('variety.variety_name'),
        ])
        ->filters([
            // Basic filter - column path matches query column
            DynamicFilter::make(
                name: 'packhouse_filter',
                column: 'packhouse.packhouse_name',
                queryColumn: 'packhouses.packhouse_name'
            ),
            
            // With options
            DynamicFilter::make(
                name: 'grade_filter',
                column: 'grade',
                queryColumn: 'grade',
                label: 'Grade',
                searchable: true
            ),

            // With custom option labels (booleans, enums, etc.)
            DynamicFilter::make(
                name: 'active_filter',
                column: 'is_active',
                queryColumn: 'is_active',
                optionsMap: [
                    1 => 'Active',
                    0 => 'Inactive',
                ],
            ),

            // With a custom options query
            DynamicFilter::make(
                name: 'status_filter',
                column: 'status',
                queryColumn: 'status',
                optionsQuery: fn (HasTable $livewire, Builder $query) => $query->whereNotNull('status')
            ),
        ]);
}
```

### Multiple Selection

```php
DynamicFilter::multiple(
    name: 'variety_filter',
    column: 'variety.variety_name',
    queryColumn: 'varieties.variety_name',
    searchable: true
)
```

### Lazy Option Loading (Searchable Only)

When `lazy: true` and `searchable: true`, options are empty until the user types. Search results are fetched on demand.

**Large lists (recommended):**

```php
DynamicFilter::make(
    name: 'customer_filter',
    column: 'customer.name',
    queryColumn: 'customers.name',
    searchable: true,
    lazy: true
)
```

**Short lists (preload is fine):**

```php
DynamicFilter::make(
    name: 'status_filter',
    column: 'status',
    queryColumn: 'status',
    searchable: true
)
```

### Relationship Filters

For filtering through relationships using `whereHas`:

```php
DynamicFilter::relationship(
    name: 'supplier_filter',
    column: 'supplier.name',
    relationship: 'supplier',
    relationshipColumn: 'name',
    multiple: true
)
```

By default, relationship filters query the related model directly (no joins) so options and search work out of the box. You can override this with `optionsQuery` if you need custom constraints:

```php
DynamicFilter::relationship(
    name: 'supplier_filter',
    column: 'supplier.name',
    relationship: 'supplier',
    relationshipColumn: 'name',
    optionsQuery: fn (HasTable $livewire, Builder $query) => $query
        ->getModel()
        ->supplier()
        ->getRelated()
        ->newQuery()
        ->where('is_active', true)
)
```

### Panel Access Control

Restrict filters to specific panels:

```php
DynamicFilter::make(
    name: 'admin_only_filter',
    column: 'internal_code',
    queryColumn: 'internal_code',
    panels: ['admin'] // Only shows in admin panel
)
```

## How It Works

The filter grabs the current table query (with all existing filters/scopes applied), plucks distinct values for the specified column when possible, and uses those as select options. Results are cached per column with a configurable TTL and scope.

Handles Carbon dates and PHP enums automatically — dates display as `d/m/Y` but filter as `Y-m-d`, enums use their `getLabel()` method for display.

## Parameters

**`DynamicFilter::make()`** and **`DynamicFilter::multiple()`**

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Filter name (must be unique) |
| `column` | string | Dot-notation path to pluck from results (e.g. `relation.field`) |
| `queryColumn` | string | Database column for the where clause (e.g. `table.field`) |
| `placeholder` | ?string | Select placeholder text |
| `label` | ?string | Filter label |
| `searchable` | bool | Enable search in select (default: false) |
| `lazy` | bool | Defer options until search (requires `searchable`; default: false) |
| `panels` | ?array | Restrict to specific panel IDs |
| `optionsMap` | ?array | Map of raw values to display labels |
| `formatOption` | ?callable | Formatter callback: `fn ($value): array|string|null` |
| `optionsQuery` | ?callable | Provide a custom options Builder or Collection: `fn (HasTable $livewire, Builder $query)` (distinct/limit apply to Builders) |

**`DynamicFilter::relationship()`** adds:

| Parameter | Type | Description |
|-----------|------|-------------|
| `relationship` | string | Relationship name for whereHas |
| `relationshipColumn` | string | Column within the relationship |
| `multiple` | bool | Allow multiple selection (default: false) |

It also supports all base parameters above (including `optionsQuery`).

## Configuration

Publish the config if you'd like to tune caching:

```bash
php artisan vendor:publish --tag="filament-dynamic-filter-config"
```

```php
return [
    'cache_ttl' => 300,
    'max_options' => null,
    'cache_scope' => 'user', // user | tenant | global
];
```

When using `tenant` scope, provide a tenant key or resolver in your config (otherwise caching is skipped for safety):

```php
'tenant_key' => null,
'tenant_resolver' => fn () => tenant('id'),
```

## Caching Notes

- Options are cached per column using a distinct query when possible.
- If a distinct query fails, the filter falls back to `$query->get()->pluck($column)`.
- Exceptions do not write to cache; they return an empty options list.
- `max_options` caps the number of options returned.

## Requirements

- PHP 8.2+
- Filament 4.x or 5.x

## License

MIT

## Credits

[16Hands](https://16hands.co.nz)
