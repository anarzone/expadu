@extends('marketing.layout')

@section('title', 'Datenschutz / Privacy — Expadu')
@section('meta_description', 'Privacy policy for expadu.com and the Expadu app.')

@section('content')
<div class="wrap prose">
    <span class="eyebrow">Privacy</span>
    <h1>Datenschutzerklärung / Privacy policy</h1>
    <p class="meta">Last updated: 18 July 2026 · Written in English — the language of this service. Eine deutsche Fassung folgt.</p>

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
        through Cloudflare, Inc. as a security/CDN layer; Cloudflare processes connection data as
        our processor under EU standard contractual clauses.
    </p>

    <h2>3. Cookies</h2>
    <p>
        We set only technically necessary cookies: a session cookie, a CSRF protection token, and —
        if you use the toggle — your theme preference in your browser's local storage. These are
        required for the site to function (§ 25 (2) TDDDG), so no consent banner is needed. We use
        no tracking, advertising or third-party analytics cookies. If that ever changes, we will
        ask for your consent first.
    </p>

    <h2>4. City waitlist</h2>
    <p>
        If you leave your e-mail and city, we store both together with a timestamp, and send you a
        single confirmation e-mail (double opt-in). Only after you confirm do we keep you on the
        list, and we use it solely to tell you when Expadu launches in your city — legal basis:
        consent (Art. 6(1)(a) GDPR). You can withdraw at any time via the link in any e-mail or by
        writing to us; withdrawal deletes your entry.
    </p>

    <h2>5. Transactional e-mail</h2>
    <p>
        Account e-mails (address verification, password resets) and waitlist confirmations are
        delivered through Brevo (Sendinblue SAS, France), an EU-based e-mail provider acting as our
        processor. Your e-mail address is shared with Brevo only for the purpose of delivering the
        message.
    </p>

    <h2>6. The Expadu app</h2>
    <p>
        If you create an account at app.expadu.com, we process the data you provide (e-mail, name,
        password — stored hashed) and, to power the product, the situation profile you set up
        (e.g. residency situation, arrival date, neighbourhood, interests) — legal basis: contract
        performance (Art. 6(1)(b) GDPR). You can also sign in with Google or Apple; in that case we
        receive your name and e-mail address from the provider, and nothing is shared back with
        them beyond the sign-in itself.
    </p>
    <p>
        If you grant browser location access, your position is used to compute departures, routes
        and nearby suggestions. Recent positions are retained for at most 7 days to power these
        features and then deleted automatically. Route and departure calculations run on our own
        servers in Germany — your location is not sent to third-party routing services.
    </p>
    <p>
        Application errors are monitored with Sentry to keep the service reliable (legitimate
        interest, Art. 6(1)(f) GDPR); error reports are scrubbed of sensitive values where
        feasible. We do not sell data, we do not use it for advertising, and we make no automated
        decisions with legal effect about you.
    </p>

    <h2>7. Retention &amp; deletion</h2>
    <p>
        Account data is kept while your account exists and deleted when you delete your account or
        ask us to. Waitlist entries are kept until you withdraw or until the launch notification
        for your city has been sent. Location data expires automatically as described above. Server
        logs rotate on a short schedule.
    </p>

    <h2>8. Your rights</h2>
    <p>
        You have the rights of access, rectification, erasure, restriction, portability and
        objection under Art. 15–21 GDPR, and the right to complain to a supervisory authority —
        in NRW: LDI Nordrhein-Westfalen. Writing to hello@expadu.com is the fastest route for any
        of these.
    </p>

    <h2>9. Changes</h2>
    <p>This policy will be updated as the product evolves; the date above always reflects the current version.</p>
</div>
@endsection
