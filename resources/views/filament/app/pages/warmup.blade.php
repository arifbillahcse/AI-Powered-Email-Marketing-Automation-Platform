<x-filament-panels::page>
    @if ($this->isLocked())
        <x-filament::empty-state
            icon="heroicon-o-lock-closed"
            icon-color="gray"
            heading="Inbox warmup is coming soon"
            description="Warmup gradually builds your mailboxes' sender reputation by exchanging real conversations with other inboxes. It will switch on here automatically once the module is enabled."
        />
    @else
        <x-filament::section heading="Inbox warmup">
            Warmup is enabled. The dashboard ships in Phase 14.
        </x-filament::section>
    @endif
</x-filament-panels::page>
