<x-mail::message>
# Almost on the list

You asked us to let you know when Expadu arrives in **{{ $city }}**.

One tap to confirm it was really you — that's it:

<x-mail::button :url="$confirmUrl">
Confirm my spot
</x-mail::button>

If you didn't request this, ignore this e-mail — unconfirmed entries are not kept on the list.

Bis bald,<br>
Expadu — built in Cologne
</x-mail::message>
