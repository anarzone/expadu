<x-mail::message>
# The last 24 hours

@if ($alerts !== [])
**Alerts:**
@foreach ($alerts as $alert)
- {{ $alert }}
@endforeach

@endif
<x-mail::table>
| | |
|:--|--:|
| New accounts | {{ ($day['signups']['web'] ?? 0) + ($day['signups']['app'] ?? 0) }} (website {{ $day['signups']['web'] ?? 0 }}, iPhone {{ $day['signups']['app'] ?? 0 }}) |
| Waitlist sign-ups | {{ $day['signups']['waitlist'] ?? 0 }}, confirmed {{ $day['waitlist_confirmed'] }} |
| Emails verified | {{ $day['verified'] }} |
| Sign-ins | {{ $day['logins'] }} |
| Failed sign-ins | {{ $day['failed_logins'] }} |
| Refused by the rate limits | {{ $day['throttled'] }} |
| Password-reset requests | {{ $day['resets'] }} |
| Turned away by the bot traps | {{ array_sum($day['blocked']) }} |
| Emails sent yesterday / today so far | {{ $mail['yesterday'] }} / {{ $mail['today'] }} of {{ $mail['daily'] }} a day |
| Emails held back | {{ $day['mail_held'] }} |
</x-mail::table>

@if ($day['blocked'] !== [])
**Why posts were turned away:** {{ collect($day['blocked'])->map(fn ($n, $reason) => ($reasons[$reason] ?? $reason).' '.$n)->implode(', ') }}

**Most-used domains in them:** {{ collect($day['blocked_domains'])->map(fn ($n, $domain) => "$domain $n")->implode(', ') }}
@endif

<x-mail::button :url="$admin">
Open the admin panel
</x-mail::button>

Expadu security watch
</x-mail::message>
