@php
    /** @var \App\Models\Lead $lead */
    $lead = $getRecord();
    $tags = $lead->tags->sortBy('name')->values();
    $shown = isset($limit) ? $tags->take($limit) : $tags;
@endphp

@if ($tags->isEmpty())
    <span class="text-sm text-gray-400 dark:text-gray-500">{{ $empty ?? '' }}</span>
@else
    <div class="flex flex-wrap items-center gap-1">
        @foreach ($shown as $tag)
            <x-filament::badge :color="$tag->color" size="sm">{{ $tag->name }}</x-filament::badge>
        @endforeach
        @if ($shown->count() < $tags->count())
            <span class="text-xs text-gray-500 dark:text-gray-400">+{{ $tags->count() - $shown->count() }}</span>
        @endif
    </div>
@endif
