@php
    $impersonating = session()->has(\App\Services\Admin\Impersonator::SESSION_KEY);
    $banner = app(\App\Services\Billing\AccountBanner::class)->for(\Filament\Facades\Filament::getTenant());
@endphp

@if ($impersonating)
    <x-filament::section compact>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
            <span class="text-sm">
                You're signed in as <strong>{{ auth()->user()?->email }}</strong> (impersonating).
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}">
                @csrf
                <x-filament::button type="submit" size="sm" color="gray">Back to admin</x-filament::button>
            </form>
        </div>
    </x-filament::section>
@endif

@if ($banner)
    <x-filament::section compact>
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
            <span class="text-sm" style="display: inline-flex; align-items: center; gap: .5rem;">
                <x-filament::badge :color="$banner['color']">
                    {{ match ($banner['color']) { 'danger' => 'Action needed', 'warning' => 'Overdue', default => 'Trial' } }}
                </x-filament::badge>
                {{ $banner['message'] }}
            </span>
            @if ($banner['url'])
                <x-filament::button tag="a" :href="$banner['url']" size="sm" :color="$banner['color'] === 'info' ? 'primary' : $banner['color']">
                    {{ $banner['link'] }}
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>
@endif
