@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title ? $title.' | '.config('app.name') : config('app.name')])
    </head>
    <body class="min-h-screen bg-white text-brand-navy">
        <x-impersonation-banner />

        <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group heading="Workspace" class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard', 'contributor.dashboard', 'client.dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                    @if (auth()->user()->isContributor())
                        <flux:navlist.item icon="briefcase" :href="route('contributor.work')" :current="request()->routeIs('contributor.work*')" wire:navigate>My work</flux:navlist.item>
                        @if (auth()->user()->isActiveContributor() && app(\App\Services\FeatureFlags\FeatureFlags::class)->enabled(\App\Enums\FeatureFlag::OpenRoles))
                            <flux:navlist.item icon="magnifying-glass" :href="route('contributor.discover')" :current="request()->routeIs('contributor.discover*')" wire:navigate>Discover</flux:navlist.item>
                            <flux:navlist.item icon="paper-airplane" :href="route('contributor.applications')" :current="request()->routeIs('contributor.applications')" wire:navigate>My applications</flux:navlist.item>
                        @endif
                        <flux:navlist.item icon="clipboard-document-check" :href="route('contributor.onboarding')" :current="request()->routeIs('contributor.onboarding')" wire:navigate>Onboarding</flux:navlist.item>
                        <flux:navlist.item icon="user-circle" :href="route('contributor.profile')" :current="request()->routeIs('contributor.profile')" wire:navigate>Profile</flux:navlist.item>
                    @endif
                    @if (auth()->user()->isClient())
                        <flux:navlist.item icon="document-text" :href="route('client.briefs.index')" :current="request()->routeIs('client.briefs.*')" wire:navigate>Briefs</flux:navlist.item>
                        <flux:navlist.item icon="briefcase" :href="route('client.projects.index')" :current="request()->routeIs('client.projects.*')" wire:navigate>Projects</flux:navlist.item>
                        <flux:navlist.item icon="check-badge" :href="route('client.deliverables')" :current="request()->routeIs('client.deliverables')" wire:navigate>Deliverables</flux:navlist.item>
                    @endif
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span class="flex h-full w-full items-center justify-center rounded-lg bg-zinc-200 text-brand-navy">
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile header -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

                <flux:menu>
                    <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>Settings</flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
