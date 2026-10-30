@php
    /** @var \App\Models\Lead $lead */
    $lead = $getRecord();
@endphp

<div class="overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-white/10">
    <table class="w-full divide-y divide-gray-200 text-start text-sm dark:divide-white/10">
        <thead class="bg-gray-50 dark:bg-white/5">
            <tr>
                <th class="px-4 py-2 text-start font-medium text-gray-950 dark:text-white">Variable</th>
                <th class="px-4 py-2 text-start font-medium text-gray-950 dark:text-white">Value</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($lead->custom_fields ?? [] as $key => $value)
                @php($link = \App\Support\SafeLink::parse((string) $value))
                <tr>
                    <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-950 dark:text-white">{{ $key }}</td>
                    <td class="break-all px-4 py-2 text-gray-950 dark:text-white">
                        @if ($link)
                            <a
                                href="{{ $link['href'] }}"
                                @if (str_starts_with($link['href'], 'http')) target="_blank" rel="noopener noreferrer" @endif
                                title="{{ $value }}"
                                class="inline-flex items-center gap-1 text-primary-600 hover:underline dark:text-primary-400"
                            >
                                {{ $link['label'] }}
                                @if (str_starts_with($link['href'], 'http'))
                                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="size-3.5" />
                                @endif
                            </a>
                        @else
                            {{ $value }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
