<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Services\Auth\AdminEmailCodeAuthentication;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Spec 17.2, SCR-040 (amending SCR-029): the second step of admin sign-in is a 6-digit code emailed to the admin. It is the first
            // provider, so it is the default. A super admin always has it and any other admin has it while the `two_factor_admin` flag is on
            // (`User::mustUseEmailCode()`), with nothing to set up. The authenticator app and its recovery codes stay as an extra for an admin
            // who has set them up; the profile page is where that is managed. `EnsureAdminTwoFactor` is kept as a guard on every page.
            ->profile()
            ->multiFactorAuthentication(
                [AdminEmailCodeAuthentication::make(), AppAuthentication::make()->recoverable()->brandName('Groibe')],
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminTwoFactor::class)
            ->brandName('Groibe Admin')
            // The supplied logo files, at their own ratio (spec 10.3): white on the dark theme, standard on light.
            ->brandLogo(asset('brand/groibe-logo.svg'))
            ->darkModeBrandLogo(asset('brand/groibe-logo-white.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::hex('#042B65'),
                'success' => Color::hex('#0EAE44'),
                'info' => Color::hex('#046CEA'),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render('<livewire:notifications.bell />'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => Blade::render('<x-impersonation-banner />'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
