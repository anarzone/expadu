<x-mail::message>
# Your code

Enter this code in Expadu to confirm it's you:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

It works once, for {{ $minutes }} minutes, and only where you asked for it.

If you didn't ask for a code, you can ignore this email. Nobody can use it without being signed in to your account.

Bis bald,<br>
Expadu — built in Cologne
</x-mail::message>
