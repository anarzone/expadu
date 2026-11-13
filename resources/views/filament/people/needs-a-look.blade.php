<x-filament-panels::page>
    @forelse ($items as $item)
        <x-filament::section compact wire:key="{{ $item['key'] }}">
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center">
                <div style="flex: 999 1 22rem; min-width: 0; display: flex; flex-direction: column; gap: .375rem">
                    <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center">
                        <x-filament::badge :color="$item['kind'] === 'account' ? 'primary' : 'info'">{{ $item['kind'] === 'account' ? 'Account' : 'Waitlist' }}</x-filament::badge>
                        <b style="overflow-wrap: anywhere">{{ $item['title'] }}</b>
                        @foreach ($item['flags'] as $flag)
                            <x-filament::badge :color="$flag['color']">{{ $flag['label'] }}</x-filament::badge>
                        @endforeach
                    </div>
                    <div style="font-size: .875rem">{{ $item['why'] }}</div>
                    <div style="font-size: .8125rem; opacity: .75">{{ $item['evidence'] }}</div>
                </div>
                <div style="flex: 1 1 20rem; display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end">
                    {{ ($this->keepAction)(['key' => $item['key']]) }}
                    @if ($item['kind'] === 'account')
                        {{ ($this->suspendAction)(['key' => $item['key']]) }}
                    @endif
                    {{ ($this->deleteAction)(['key' => $item['key']]) }}
                </div>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <div style="display: flex; flex-direction: column; gap: .375rem; align-items: center; text-align: center; padding: 1.5rem 0">
                <x-filament::icon icon="heroicon-o-check-circle" style="width: 2rem; height: 2rem; color: #16a34a" />
                <b>Nothing needs a look.</b>
                <span style="font-size: .875rem; opacity: .75">New flags show here, and in the morning summary.</span>
            </div>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
