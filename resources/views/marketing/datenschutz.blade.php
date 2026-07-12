@extends('marketing.layout')

@section('title', 'Datenschutz / Privacy — Expadu')
@section('meta_description', 'Privacy policy for expadu.com and the Expadu app.')

@section('content')
<div class="wrap prose">
    <span class="eyebrow">Privacy</span>
    <h1>Datenschutzerklärung / Privacy policy</h1>
    <p class="meta">Last updated: {{ now()->format('j F Y') }} · Written in English — the language of this service. Eine deutsche Fassung folgt.</p>

    <div class="todo-box">
        <b>Draft for legal review before public launch.</b> Content below reflects the actual
        current processing, but has not yet been reviewed by a lawyer.
    </div>

    <h2>1. Who is responsible</h2>
    <p>
        Expadu — Anar Haqverdiyev, Köln, Germany (contact: hello@expadu.com) is the controller for
        the processing described here.
    </p>

    <h2>2. Visiting this website</h2>
    <p>
        Our servers are operated by Hetzner Online GmbH in Germany. When you visit, standard server
        logs are processed (IP address, time, requested page, user agent) to deliver the site
        securely and diagnose faults — legal basis: legitimate interest (Art. 6(1)(f) GDPR). Logs
        are short-lived and not used to profile you. On our production domain, traffic is routed
        through Cloudflare as a security/CDN layer.
    </p>

    <h2>3. Cookies</h2>
    <p>
        We set only technically necessary cookies: a session cookie, a CSRF protection token, and —
        if you use the toggle — your theme preference in your browser's local storage. No tracking
        or advertising cookies, which is why there is no cookie banner.
    </p>

    <h2>4. City waitlist</h2>
    <p>
        If you leave your e-mail and city, we store both together with a timestamp, and send you a
        single confirmation e-mail (double opt-in). Only after you confirm do we keep you on the
        list, and we use it solely to tell you when Expadu launches in your city — legal basis:
        consent (Art. 6(1)(a) GDPR). E-mail delivery runs through our e-mail provider (Resend).
        You can withdraw at any time via the link in any e-mail or by writing to us; withdrawal
        deletes your entry.
    </p>

    <h2>5. The Expadu app</h2>
    <p>
        If you create an account at app.expadu.com, we process the data you provide (e-mail, name,
        password — stored hashed) and, to power the product, the situation profile you set up
        (e.g. residency situation, arrival date, neighbourhood, interests) — legal basis: contract
        performance (Art. 6(1)(b) GDPR). If you grant browser location access, your position is
        used to compute departures, routes and nearby suggestions; recent positions are retained
        briefly for these features and then expire automatically. Application errors are monitored
        with Sentry to keep the service reliable (legitimate interest). We do not sell data and do
        not use it for advertising.
    </p>

    <h2>6. Your rights</h2>
    <p>
        You have the rights of access, rectification, erasure, restriction, portability and
        objection under Art. 15–21 GDPR, and the right to complain to a supervisory authority —
        in NRW: LDI Nordrhein-Westfalen. Writing to hello@expadu.com is the fastest route for any
        of these.
    </p>

    <h2>7. Changes</h2>
    <p>This policy will be updated as the product evolves; the date above always reflects the current version.</p>
</div>
@endsection
