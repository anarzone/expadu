<div style="display: flex; flex-direction: column; gap: 1.25rem; font-size: .875rem">
    <x-filament::callout
        :color="$profile['scripted'] ? 'warning' : 'success'"
        :icon="$profile['scripted'] ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'"
        :description="$profile['verdict']"
    />

    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem">
        @forelse ($profile['counts'] as $type => $n)
            <div style="border: 1px solid rgb(127 127 127 / .2); border-radius: .5rem; padding: .5rem .75rem; display: flex; flex-direction: column">
                <span style="font-size: .75rem; opacity: .75">{{ $types[$type][0] ?? $type }}</span>
                <b style="font-size: 1.125rem">{{ number_format($n) }}</b>
            </div>
        @empty
            <p style="opacity: .75">Nothing in the log from this address.</p>
        @endforelse
    </div>

    <dl style="display: grid; grid-template-columns: auto 1fr; gap: .375rem 1rem; margin: 0">
        <dt style="opacity: .75">First seen</dt><dd style="margin: 0; text-align: right">{{ $profile['first']?->format('j M, H:i') ?? '–' }}</dd>
        <dt style="opacity: .75">Last seen</dt><dd style="margin: 0; text-align: right">{{ $profile['last']?->format('j M, H:i') ?? '–' }}</dd>
        <dt style="opacity: .75">Browsers</dt>
        <dd style="margin: 0; text-align: right; word-break: break-word">
            @forelse ($profile['browsers'] as $browser)
                <div title="{{ $browser }}">{{ \App\Security\SecurityStats::browser($browser) }}</div>
            @empty
                –
            @endforelse
        </dd>
    </dl>

    <div style="display: flex; flex-direction: column; gap: .5rem">
        <b style="font-size: .8125rem">Accounts from this address</b>
        @forelse ($profile['accounts'] as $account)
            <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('edit', ['record' => $account['id']]) }}"
               style="display: flex; justify-content: space-between; gap: .5rem; padding: .5rem .75rem; border: 1px solid rgb(127 127 127 / .2); border-radius: .5rem">
                <span><b>{{ $account['name'] }}</b><br><span style="opacity: .75">{{ $account['email'] }}</span></span>
                <span style="opacity: .75">{{ $account['what'] }}</span>
            </a>
        @empty
            <p style="opacity: .75; margin: 0">None: nothing it sent got through.</p>
        @endforelse
    </div>
</div>
