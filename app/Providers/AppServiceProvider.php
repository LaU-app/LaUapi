<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use App\Models\su_ad;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tokens aislados: un token del panel de LaU (superusuario) solo vale en /api/lau-admin/*,
        // y un token de estudiante nunca vale ahí. Así un superusuario no puede actuar como un
        // usuario de la red social (los ids de ambas tablas se pisan) ni al revés.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $isAdminToken = $token->tokenable_type === (new su_ad)->getMorphClass();

            return $isAdminToken === request()->is('api/lau-admin/*');
        });

        // Configurar vista personalizada para paginación
        Paginator::defaultView('custom.pagination');
        Paginator::defaultSimpleView('custom.simple-pagination');
        
        // Usar en automatico el protocolo segun este en .env (APP_URL)
        if (str_starts_with(config('app.url'), 'https://') && 
            !str_contains(config('app.url'), 'localhost') && 
            !str_contains(config('app.url'), '127.0.0.1')) {
            URL::forceScheme('https');
        }
    }
}
