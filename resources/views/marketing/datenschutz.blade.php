@extends('marketing.layout')

@section('tone', 'cyan')
@section('theme_color', '#05badd')

@section('title', 'Datenschutz / Privacy — Expadu')
@section('meta_description', 'Privacy policy for expadu.com and the Expadu app.')

@section('hero')
    <div class="wrap mk-hero__prose">
    <span class="eyebrow">Privacy</span>
    <h1><span lang="de">Datenschutzerklärung</span> / Privacy policy</h1>
    <p class="meta">Last updated: 21 October 2026 · Written in English — the language of this service. Eine deutsche Fassung folgt.</p>
    </div>
@endsection

@section('content')
<div class="wrap prose">

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

    <h2>4. Launch waitlist</h2>
    <p>
        If you join the waitlist, we store your e-mail address, the city you enter (Cologne unless
        you change it), the page you signed up from and a timestamp, and send you a single
        confirmation e-mail (double opt-in). Only after you confirm do we keep you on the list, and
        we use it solely to tell you when the new Expadu app launches — legal basis: consent
        (Art. 6(1)(a) GDPR). The page you signed up from only tells us which page brought you; it is
        not used for tracking. You can withdraw at any time via the link in any e-mail or by writing
        to us; withdrawal deletes your entry.
    </p>

    <h2>5. Transactional e-mail</h2>
    <p>
        Account e-mails (address verification, password resets) and waitlist confirmations are
        delivered through Resend (Resend, Inc.) acting as our processor; our sending domain is
        configured to Resend's EU region, so delivery is processed on servers in the EU (Ireland).
        Your e-mail address is shared with Resend only for the purpose of delivering the message.
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
        features and then deleted automatically.
    </p>
    <p>
        Route and place searches use the configured routing and search providers. Self-hosted
        MOTIS is used when configured; requests can also go to Transitous and the configured
        TRIAS service. Requests can include starting-point and destination coordinates, search
        text, travel mode and requested departure or arrival times.
    </p>
    <p>
        When you open a map, your browser loads map styles and tiles directly from OpenFreeMap.
        These requests share your IP address and the requested map areas with that provider.
    </p>
    <p>
        Application errors are monitored with Sentry to keep the service reliable (legitimate
        interest, Art. 6(1)(f) GDPR); error reports are scrubbed of sensitive values where
        feasible. We do not sell data, we do not use it for advertising, and we make no automated
        decisions with legal effect about you.
    </p>

    <h3>AI features (Anthropic)</h3>
    <p>
        Some features use Claude, an AI service from Anthropic PBC (USA), which processes the
        request for us under its commercial terms and data processing addendum. Anthropic does not
        use this data to train its models. The AI never decides anything about you; times,
        places, fees and legal facts always come from our own checked data, not from the AI.
    </p>
    <ul>
        <li>
            <strong>Composer.</strong> When you send a request in Composer, what you typed, your
            home neighbourhood and the current time are sent to Anthropic to read the request.
            The line next to the box says so; sending is your consent for that one request
            (Art. 6(1)(a) GDPR). Nothing else from your account is sent.
        </li>
        <li>
            <strong>Paperwork: your own words and questions.</strong> When you tap “Suggest an
            answer”, what you typed and the question it answers are sent to Anthropic; the
            suggestion goes into your record only when you confirm it. When you tap “Ask”, your
            question is sent with the text of the matching source-checked cards and only those
            answers on your record that those cards need (dates and counts are sent as
            “recorded”, never their value); the reply comes only from those cards. Each tap is
            your consent for that one request (Art. 6(1)(a) GDPR).
        </li>
        <li>
            <strong>Public information.</strong> Event listings, public transport notices and
            place descriptions are translated or summarised from public sources. No personal data
            is involved.
        </li>
    </ul>
    <p>
        Anthropic’s reply is kept by us for at most 15 minutes for that request. Transfers to the
        USA are covered by the European Commission’s standard contractual clauses in Anthropic’s
        data processing addendum. You can withdraw your consent at any time; it then ends for any
        request not yet sent.
    </p>

    <h3>Personalisation (opt-in)</h3>
    <p>
        If you turn on personalisation in your settings, we learn from what you do in the app,
        such as which places and events you open, save, travel to or turn down, to rank
        suggestions for you (Art. 6(1)(a) GDPR). This is stored under a pseudonymous key, never
        includes your location or anything you type, and individual records are deleted after 90
        days. Turning personalisation off deletes everything learned. It is off unless you turn
        it on.
    </p>

    <h2>7. Retention &amp; deletion</h2>
    <p>
        Account data is kept while your account exists and deleted when you delete your account or
        ask us to. Waitlist entries are kept until you withdraw or until the launch notification
        has been sent. Location data expires automatically as described above. Server
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
