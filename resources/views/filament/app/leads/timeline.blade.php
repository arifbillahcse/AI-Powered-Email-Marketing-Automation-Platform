@php
    /** @var \App\Models\Lead $lead */
    $lead = $getRecord();
    $activities = $lead->activities()->with('user')->limit(100)->get();
@endphp

{{-- Plain scoped CSS on purpose: this view must look right even when the panel theme was built before it existed (cPanel has no Node, so the theme is only rebuilt by the release zip). --}}
<style>
    .lead-timeline { list-style: none; margin: 0; padding: 0 0 0 1.5rem; border-left: 1px solid var(--gray-200, #e5e7eb); }
    .lead-timeline > li { position: relative; padding-bottom: 1rem; }
    .lead-timeline > li:last-child { padding-bottom: 0; }
    .lead-timeline .dot { position: absolute; left: -2.25rem; top: 0; display: flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: #fff; box-shadow: 0 0 0 1px var(--gray-200, #e5e7eb); color: var(--gray-500, #6b7280); }
    .lead-timeline .dot svg { width: .875rem; height: .875rem; }
    .lead-timeline .dot.primary { color: var(--primary-600, #4f46e5); }
    .lead-timeline .dot.danger { color: var(--danger-600, #e11d48); }
    .lead-timeline .dot.info { color: var(--info-600, #0284c7); }
    .lead-timeline .dot.success { color: var(--success-600, #059669); }
    .lead-timeline .what { margin: 0; font-size: .875rem; line-height: 1.25rem; font-weight: 500; color: var(--gray-950, #030712); word-break: break-word; }
    .lead-timeline .when { margin: 0; font-size: .75rem; line-height: 1rem; color: var(--gray-500, #6b7280); }
    .lead-timeline-empty { margin: 0; font-size: .875rem; color: var(--gray-500, #6b7280); }
    .dark .lead-timeline { border-left-color: rgba(255, 255, 255, .1); }
    .dark .lead-timeline .dot { background: var(--gray-900, #111827); box-shadow: 0 0 0 1px rgba(255, 255, 255, .1); }
    .dark .lead-timeline .dot.primary { color: var(--primary-400, #818cf8); }
    .dark .lead-timeline .dot.danger { color: var(--danger-400, #fb7185); }
    .dark .lead-timeline .dot.info { color: var(--info-400, #38bdf8); }
    .dark .lead-timeline .dot.success { color: var(--success-400, #34d399); }
    .dark .lead-timeline .what { color: #fff; }
</style>

@if ($activities->isEmpty())
    <p class="lead-timeline-empty">No activity yet.</p>
@else
    <ol class="lead-timeline">
        @foreach ($activities as $activity)
            <li>
                <span class="dot {{ $activity->type->getColor() }}">
                    <x-filament::icon :icon="$activity->type->getIcon()" />
                </span>
                <p class="what">{{ $activity->description }}</p>
                <p class="when">
                    <time datetime="{{ $activity->created_at?->toIso8601String() }}" title="{{ $activity->created_at }}">{{ $activity->created_at?->diffForHumans() }}</time>
                    @if ($activity->user)
                        · {{ $activity->user->name }}
                    @endif
                </p>
            </li>
        @endforeach
    </ol>
@endif
