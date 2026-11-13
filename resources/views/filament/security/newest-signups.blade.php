@php
    $cell = 'padding: .75rem; vertical-align: middle; white-space: nowrap; border-top: 1px solid rgb(127 127 127 / .15)';
    $head = 'padding: .5rem .75rem; text-align: left; font-size: .75rem; font-weight: 600; opacity: .75; white-space: nowrap';
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Newest sign-ups">
        <x-slot name="afterHeader">
            <x-filament::link :href="$log" size="sm">All in the log</x-filament::link>
        </x-slot>

        @if ($rows === [])
            <p style="opacity: .75; font-size: .875rem">No sign-ups yet.</p>
        @else
            <div style="overflow-x: auto; margin: -.5rem -.75rem">
                <table style="width: 100%; min-width: 700px; border-collapse: collapse; font-size: .875rem">
                    <thead>
                        <tr>
                            <th style="{{ $head }}">Who</th>
                            <th style="{{ $head }}">Where</th>
                            <th style="{{ $head }}">When</th>
                            <th style="{{ $head }}">State</th>
                            <th style="{{ $head }}; text-align: center">Composer, 7 days</th>
                            <th style="{{ $head }}">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $cell }}">
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" style="font-weight: 600">{{ $row['name'] }}</a>
                                    @else
                                        <span style="font-weight: 600">{{ $row['name'] }}</span>
                                    @endif
                                    <div style="opacity: .75; font-size: .8125rem; max-width: 14rem; overflow: hidden; text-overflow: ellipsis" title="{{ $row['email'] }}">{{ $row['email'] }}</div>
                                </td>
                                <td style="{{ $cell }}; min-width: 6rem">
                                    <x-filament::badge color="gray">{{ $row['where'] }}</x-filament::badge>
                                    @if ($row['via'])
                                        <div style="opacity: .75; font-size: .75rem; margin-top: .25rem">{{ $row['via'] }}</div>
                                    @endif
                                </td>
                                <td style="{{ $cell }}" title="{{ $row['at']->timezone(config('app.timezone'))->format('j M Y, H:i') }}">{{ $row['at']->diffForHumans() }}</td>
                                <td style="{{ $cell }}; min-width: 7.5rem"><x-filament::badge :color="$row['state_color']">{{ $row['state'] }}</x-filament::badge></td>
                                <td style="{{ $cell }}; text-align: center">{{ $row['composer'] ?? '–' }}</td>
                                <td style="{{ $cell }}">
                                    <div style="display: flex; gap: .375rem; flex-wrap: wrap">
                                        @forelse ($row['flags'] as $flag)
                                            <x-filament::badge :color="$flag['color']" title="{{ $flag['why'] }}">{{ $flag['label'] }}</x-filament::badge>
                                        @empty
                                            <span style="opacity: .5">–</span>
                                        @endforelse
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
