<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => 300,

    /*
    |--------------------------------------------------------------------------
    | Maximum number of options to return
    |--------------------------------------------------------------------------
    | Set to null to disable limiting.
    */
    'max_options' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache scope: user | tenant | global
    |--------------------------------------------------------------------------
    */
    'cache_scope' => 'user',

    /*
    |--------------------------------------------------------------------------
    | Tenant cache key (optional)
    |--------------------------------------------------------------------------
    | Provide a fixed tenant key or a resolver callback when using tenant scope.
    */
    'tenant_key' => null,
    'tenant_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Default select placeholder
    |--------------------------------------------------------------------------
    | Placeholder shown in filter selects when no per-filter placeholder is
    | given. null keeps the historic "Select {label}..." text; use '' for no
    | placeholder (the filter label usually says enough), or e.g. 'Any'.
    */
    'placeholder' => null,
];
