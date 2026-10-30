@php
    /** @var \App\Models\Lead $lead */
    $lead = $getRecord();
@endphp

{{-- Plain scoped CSS on purpose: see the note in timeline.blade.php. --}}
<style>
    .lead-fields { width: 100%; border-collapse: collapse; font-size: .875rem; line-height: 1.25rem; }
    .lead-fields th { padding: .5rem 1rem; text-align: left; font-weight: 600; color: var(--gray-950, #030712); background: var(--gray-50, #f9fafb); border-bottom: 1px solid var(--gray-200, #e5e7eb); }
    .lead-fields td { padding: .5rem 1rem; vertical-align: top; color: var(--gray-950, #030712); border-bottom: 1px solid var(--gray-200, #e5e7eb); word-break: break-word; }
    .lead-fields tr:last-child td { border-bottom: 0; }
    .lead-fields th:first-child, .lead-fields td:first-child { width: 14rem; white-space: nowrap; }
    .lead-fields td:first-child { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; }
    .lead-fields a { display: inline-flex; align-items: center; gap: .25rem; color: var(--primary-600, #4f46e5); text-decoration: none; }
    .lead-fields a:hover { text-decoration: underline; }
    .lead-fields a svg { width: .875rem; height: .875rem; flex: none; }
    .lead-fields-wrap { overflow-x: auto; border-radius: .5rem; box-shadow: 0 0 0 1px var(--gray-200, #e5e7eb); }
    .dark .lead-fields th { color: #fff; background: rgba(255, 255, 255, .05); border-bottom-color: rgba(255, 255, 255, .1); }
    .dark .lead-fields td { color: #fff; border-bottom-color: rgba(255, 255, 255, .1); }
    .dark .lead-fields a { color: var(--primary-400, #818cf8); }
    .dark .lead-fields-wrap { box-shadow: 0 0 0 1px rgba(255, 255, 255, .1); }
</style>

<div class="lead-fields-wrap">
    <table class="lead-fields">
        <thead>
            <tr>
                <th>Variable</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lead->custom_fields ?? [] as $key => $value)
                @php($link = \App\Support\SafeLink::parse((string) $value))
                <tr>
                    <td>{{ $key }}</td>
                    <td>
                        @if ($link)
                            @php($external = str_starts_with($link['href'], 'http'))
                            <a
                                href="{{ $link['href'] }}"
                                @if ($external) target="_blank" rel="noopener noreferrer" @endif
                                title="{{ $value }}"
                            >
                                {{ $link['label'] }}
                                @if ($external)
                                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" />
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
