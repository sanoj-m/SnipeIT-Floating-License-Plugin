<?php

namespace SnipeIt\FloatingLicenses;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SnipeIt\FloatingLicenses\Console\ExpireFloatingAllocations;

class FloatingLicensesServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/floating-licenses.php', 'floating-licenses');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'floating-licenses');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'floating-licenses');

        Route::group(['middleware' => ['web', 'auth']], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->registerGates();
        $this->mergePermissions();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireFloatingAllocations::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/floating-licenses.php' => config_path('floating-licenses.php'),
            ], 'floating-licenses-config');
        }
    }

    /**
     * Define authorization gates, mirroring how AuthServiceProvider defines
     * gates such as reports.view. Kept here so no core files are touched.
     */
    protected function registerGates(): void
    {
        foreach (self::permissions() as $permission) {
            Gate::define($permission, fn ($user) => $user->hasAccess($permission));
        }
    }

    /**
     * Merge a "Floating Licenses" section into config('permissions') at
     * runtime so the group permission checkboxes UI picks it up without
     * editing config/permissions.php (which is marked DO NOT EDIT).
     */
    protected function mergePermissions(): void
    {
        $section = [];
        foreach (self::permissions() as $permission) {
            $section[] = [
                'permission' => $permission,
                'display' => true,
            ];
        }

        config(['permissions' => array_merge(config('permissions', []), ['Floating Licenses' => $section])]);
    }

    /**
     * The full list of permissions this package provides.
     *
     * @return string[]
     */
    public static function permissions(): array
    {
        return [
            'floating_licenses.view',
            'floating_licenses.manage',
            'floating_licenses.allocate',
            'floating_licenses.release',
            'floating_licenses.costs',
            'floating_licenses.history',
        ];
    }
}
