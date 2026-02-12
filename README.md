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

The filter grabs the current table query (with all existing filters/scopes applied), plucks the unique values from the specified column, and uses those as select options. Results are cached for 5 minutes per user/query combination to keep things quick.

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
| `panels` | ?array | Restrict to specific panel IDs |
| `optionsMap` | ?array | Map of raw values to display labels |
| `formatOption` | ?callable | Formatter callback: `fn ($value): array|string|null` |

**`DynamicFilter::relationship()`** adds:

| Parameter | Type | Description |
|-----------|------|-------------|
| `relationship` | string | Relationship name for whereHas |
| `relationshipColumn` | string | Column within the relationship |
| `multiple` | bool | Allow multiple selection (default: false) |

## Requirements

- PHP 8.2+
- Filament 4.x or 5.x

## License

MIT

## Credits

Made by [16Hands](https://16hands.co.nz)
