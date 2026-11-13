<x-filament-widgets::widget>
    @if ($alert)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            :heading="$alert['at']->format('H:i').' today'"
            :description="$alert['summary'].' Emailed to you; the traps kept turning bots away.'"
        >
            <x-slot name="footer">
                <x-filament::link :href="$log" color="warning" icon="heroicon-m-arrow-right" icon-position="after">Open the log</x-filament::link>
            </x-slot>
        </x-filament::callout>
    @endif
</x-filament-widgets::widget>
