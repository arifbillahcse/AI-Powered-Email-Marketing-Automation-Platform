@php
    /** @var \App\Models\Lead $lead */
    $lead = $getRecord();
    $activities = $lead->activities()->with('user')->limit(100)->get();
@endphp

@if ($activities->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No activity yet.</p>
@else
    <ol class="relative space-y-4 border-s border-gray-200 ps-6 dark:border-white/10">
        @foreach ($activities as $activity)
            <li class="relative">
                <span class="absolute -start-[1.95rem] flex size-6 items-center justify-center rounded-full bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                    <x-filament::icon
                        :icon="$activity->type->getIcon()"
                        @class([
                            'size-3.5',
                            'text-primary-600 dark:text-primary-400' => $activity->type->getColor() === 'primary',
                            'text-danger-600 dark:text-danger-400' => $activity->type->getColor() === 'danger',
                            'text-info-600 dark:text-info-400' => $activity->type->getColor() === 'info',
                            'text-gray-500 dark:text-gray-400' => $activity->type->getColor() === 'gray',
                        ])
                    />
                </span>
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $activity->description }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <time datetime="{{ $activity->created_at?->toIso8601String() }}" title="{{ $activity->created_at }}">{{ $activity->created_at?->diffForHumans() }}</time>
                    @if ($activity->user)
                        · {{ $activity->user->name }}
                    @endif
                </p>
            </li>
        @endforeach
    </ol>
@endif
