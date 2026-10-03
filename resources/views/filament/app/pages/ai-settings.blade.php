<x-filament-panels::page>
    @if ($this->isLocked())
        <x-filament::empty-state
            icon="heroicon-o-lock-closed"
            icon-color="gray"
            heading="AI personalization isn't enabled"
            description="AI-written first lines, subjects and emails will appear here once the AI module is switched on for this server."
        />
    @else
        {{ $this->form }}
    @endif
</x-filament-panels::page>
