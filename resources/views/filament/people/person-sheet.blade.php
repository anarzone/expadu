@php
    $labels = \App\Filament\Resources\SecurityEvents\Tables\SecurityEventsTable::Types;
    $adminActions = ['suspend' => 'Suspended by an admin', 'unsuspend' => 'Suspension lifted', 'keep' => 'Kept by an admin'];
@endphp
<div style="display: flex; flex-direction: column; gap: 1.25rem; font-size: .875rem">
    @if ($user->isSuspended())
        <x-filament::callout color="danger" icon="heroicon-o-no-symbol"
            :description="'Suspended '.$user->suspended_at->format('j M, H:i').'. Signed out everywhere and can’t sign in until it’s lifted.'" />
    @endif

    <dl style="display: grid; grid-template-columns: auto 1fr; gap: .375rem 1rem; margin: 0">
        <dt style="opacity: .75">Joined</dt><dd style="margin: 0; text-align: right">{{ $user->created_at?->format('j M Y, H:i') }}</dd>
        <dt style="opacity: .75">From</dt><dd style="margin: 0; text-align: right">{{ $from }}</dd>
        <dt style="opacity: .75">Email</dt>
        <dd style="margin: 0; text-align: right">
            @if ($user->email_verified_at)
                Verified
            @elseif ($user->verification_reminded_at)
                Not verified · reminded {{ $user->verification_reminded_at->format('j M') }}, deleted after {{ $user->verification_reminded_at->addDays((int) config('cleanup.accounts.reminder_days_before'))->format('j M') }}
            @else
                Not verified
            @endif
        </dd>
        <dt style="opacity: .75">Two-step sign-in</dt><dd style="margin: 0; text-align: right">{{ $user->hasEnabledTwoFactorAuthentication() ? 'On' : 'Off' }}</dd>
        <dt style="opacity: .75">Composer, 7 days</dt><dd style="margin: 0; text-align: right">{{ $composer }}</dd>
    </dl>

    <div style="display: flex; flex-direction: column; gap: .5rem">
        <b style="font-size: .8125rem">Recent</b>
        @forelse ($history as $event)
            <div style="display: flex; justify-content: space-between; gap: .5rem; padding: .5rem .75rem; border: 1px solid rgb(127 127 127 / .2); border-radius: .5rem">
                <span>{{ $event->type === 'admin_action' ? ($adminActions[$event->reason] ?? 'Admin: '.str_replace('_', ' ', (string) $event->reason)) : ($labels[$event->type][0] ?? $event->type) }}@if ($event->channel !== 'admin') · {{ \App\Security\SecurityStats::Channels[$event->channel] ?? $event->channel }}@endif</span>
                <span style="opacity: .75; font-variant-numeric: tabular-nums; text-align: right">{{ $event->created_at->format('j M, H:i') }}@if ($event->ip) · {{ $event->ip }}@endif</span>
            </div>
        @empty
            <p style="opacity: .75; margin: 0">Nothing in the security log for this account yet.</p>
        @endforelse
        <x-filament::link :href="\App\Filament\Resources\SecurityEvents\SecurityEventResource::getUrl('index', ['search' => $user->email])" size="sm">All in the security log</x-filament::link>
    </div>
</div>
