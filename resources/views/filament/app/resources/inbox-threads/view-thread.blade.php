<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; gap: 1rem;">
        @forelse ($this->timeline() as $item)
            <x-filament::section compact>
                <x-slot name="heading">
                    <span style="display: inline-flex; align-items: center; gap: .5rem; flex-wrap: wrap;">
                        <x-filament::badge :color="$item['badge_color']">{{ $item['badge'] }}</x-filament::badge>
                        <span>{{ $item['from'] }}</span>
                    </span>
                </x-slot>

                <x-slot name="description">
                    {{ $item['subject'] ?? '(no subject)' }}
                    @if ($item['at'])
                        · <time datetime="{{ $item['at']->toIso8601String() }}">{{ $item['at']->diffForHumans() }}</time>
                    @endif
                </x-slot>

                @if ($item['body'] !== null)
                    {{-- Plain text, escaped: email HTML is never rendered in the app. --}}
                    <div style="white-space: pre-wrap; overflow-wrap: anywhere; font-size: .875rem; line-height: 1.5;">{{ $item['body'] }}</div>
                @endif

                @if (filled($item['error'] ?? null))
                    <p style="margin-top: .5rem; font-size: .875rem;" class="text-danger-600 dark:text-danger-400">{{ $item['error'] }}</p>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>No messages yet.</x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
