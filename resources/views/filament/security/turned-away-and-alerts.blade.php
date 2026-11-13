<x-filament-widgets::widget>
    <div style="display: flex; flex-direction: column; gap: 1.5rem">
        <x-filament::section heading="Why posts were turned away">
            @if ($reasons === [])
                <p style="opacity: .75; font-size: .875rem">Nothing turned away in this period.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: .75rem">
                    @foreach ($reasons as $reason)
                        <div style="display: flex; flex-direction: column; gap: .25rem">
                            <div style="display: flex; justify-content: space-between; font-size: .875rem">
                                <span>{{ $reason['label'] }}</span><b>{{ number_format($reason['count']) }}</b>
                            </div>
                            <div style="height: .375rem; border-radius: 999px; background: rgb(249 115 22 / .15)" role="img" aria-label="{{ $reason['label'] }}: {{ $reason['count'] }}">
                                <div style="height: 100%; border-radius: 999px; background: #f97316; width: {{ $reason['width'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                    @if ($domains !== [])
                        <p style="font-size: .8125rem; opacity: .85; margin: .25rem 0 0; padding-top: .75rem; border-top: 1px solid rgb(127 127 127 / .15)">
                            Most-used domains:
                            @foreach ($domains as $domain => $n)
                                <b>{{ $domain }}</b> {{ $n }}@if (! $loop->last) · @endif
                            @endforeach
                        </p>
                    @endif
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Alerts">
            @if ($alerts === [])
                <p style="opacity: .75; font-size: .875rem">No alerts yet. You’ll get an email the moment there is one.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: .75rem">
                    @foreach ($alerts as $alert)
                        <div style="display: flex; gap: .625rem; align-items: flex-start; font-size: .875rem">
                            <span aria-hidden="true" style="flex: none; margin-top: .4rem; width: .5rem; height: .5rem; border-radius: 999px; background: {{ $alert['at']->isToday() ? '#d97706' : '#9ca3af' }}"></span>
                            <div style="display: flex; flex-direction: column; gap: .125rem">
                                <span>{{ $alert['summary'] }}</span>
                                <span style="font-size: .75rem; opacity: .75">{{ $alert['at']->format('D j M, H:i') }}{{ $alert['emailed'] ? ' · emailed' : '' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
