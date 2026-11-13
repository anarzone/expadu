<x-mail::message>
# Is this your account?

Someone made an Expadu account with this email address, but it hasn't been verified yet. If it's yours, verify it and it stays:

<x-mail::button :url="$verifyUrl">
Verify my email
</x-mail::button>

If it isn't verified by **{{ $date }}**, the account and everything in it are deleted.

Didn't make an account? Ignore this email: nothing else will be sent, and the account goes on {{ $date }}.

Bis bald,<br>
Expadu — built in Cologne
</x-mail::message>
