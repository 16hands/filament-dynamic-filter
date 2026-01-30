<?php

namespace SixteenHands\FilamentDynamicFilter;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentDynamicFilterServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-dynamic-filter';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name);
    }
}
