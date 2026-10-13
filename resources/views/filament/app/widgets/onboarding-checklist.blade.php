{{-- Inline layout styles: hosts without a Vite build (cPanel) only have Filament's bundled CSS. --}}
<x-filament-widgets::widget>
    <x-filament::section
        heading="Get started"
        :description="$done . ' of ' . count($steps) . ' done. Finish these steps to send your first campaign.'"
    >
        <x-slot name="afterHeader">
            <x-filament::link tag="button" color="gray" size="sm" wire:click="dismiss">
                Hide checklist
            </x-filament::link>
        </x-slot>

        <ol style="display: grid; gap: 0.875rem; grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); margin: 0; padding: 0; list-style: none;">
            @foreach ($steps as $step)
                <li style="display: flex; align-items: flex-start; gap: 0.625rem;">
                    <span style="flex-shrink: 0; margin-top: 0.125rem; color: {{ $step['done'] ? 'var(--success-500)' : 'var(--gray-400)' }};">
                        <x-filament::icon
                            :icon="$step['done'] ? \Filament\Support\Icons\Heroicon::CheckCircle : \Filament\Support\Icons\Heroicon::OutlinedEllipsisHorizontalCircle"
                            style="width: 1.25rem; height: 1.25rem;"
                        />
                    </span>
                    <div style="font-size: 0.875rem; line-height: 1.25rem;">
                        @if ($step['done'])
                            <span style="text-decoration: line-through; opacity: 0.6;">{{ $step['label'] }}</span>
                        @else
                            <x-filament::link :href="$step['url']">{{ $step['label'] }}</x-filament::link>
                            <div style="opacity: 0.7;">{{ $step['description'] }}</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>
