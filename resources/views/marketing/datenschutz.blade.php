@extends('marketing.layout')

@section('tone', 'cyan')
@section('theme_color', '#05badd')

@section('title', 'Datenschutz / Privacy — Expadu')
@section('meta_description', 'Privacy policy for expadu.com and the Expadu app.')

@section('hero')
    <div class="wrap mk-hero__prose">
    <span class="eyebrow">Privacy</span>
    <h1><span lang="de">Datenschutzerklärung</span> / Privacy policy</h1>
    <p class="meta">Last updated: 17 November 2026 · Written in English — the language of this service. Eine deutsche Fassung folgt.</p>
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

    <h3>Security log</h3>
    <p>
        To protect accounts and our forms from abuse, we keep a security log of sign-ups (including
        waitlist sign-ups), sign-ins and failed sign-ins, password-reset requests, e-mail
        verifications, and form posts or e-mails our automatic checks held back. Each entry records
        the time, what happened, your account if you have one, the part of the e-mail address after
        the “@” (never the full address), your IP address and your browser’s user agent. We use it
        only to detect and stop attacks such as automated sign-ups, password guessing and e-mail
        flooding, and to alert us when one starts — legal basis: legitimate interest (Art. 6(1)(f)
        GDPR). Entries are deleted automatically after 90 days. Your own entries are included when
        you download your data.
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
        If you create an account at app.expadu.com, we process the data you provide: your e-mail
        address, name and password (stored hashed). You can also sign in with Google; we then
        receive your name and e-mail address from Google, and nothing is shared back beyond the
        sign-in itself. Legal basis: contract performance (Art. 6(1)(b) GDPR).
    </p>
    <p>
        In Paperwork you can keep a record of your situation (for example your residence
        situation, your arrival date and your city), your documents and your appointments, so the
        app can show the steps that apply to you and when they are due. You can also keep records
        for family members you look after, or give someone you invite access to yours. Records are
        kept with your account and used only for this; you can stop their processing or erase
        them at any time.
        In Travel you can save places such as home and work, to plan trips from and to them.
        Legal basis: contract performance (Art. 6(1)(b) GDPR).
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
    <h3>The iPhone app</h3>
    <p>
        The Expadu app for iPhone uses the same account and the same data as the web app. You can
        sign in with your e-mail address, with Google or with Apple. With Apple, we receive an
        identifier from Apple and, if you choose to share it, your e-mail address (or Apple’s
        private relay address). Legal basis: contract performance (Art. 6(1)(b) GDPR).
    </p>
    <p>
        If you allow location access on your iPhone (while using the app), the app sends your
        position with the requests that need it: departures near you, routes, and when to leave.
        It is used for that request only. A trip you start keeps where it started for at most
        24 hours.
    </p>
    <p>
        While a trip you started is running, the app keeps following your position on the iPhone,
        even with the screen off; iOS shows this in the status bar. It uses it to tell you where to
        get off and when a change is at risk. Your position stays on the phone: to work out which
        tram you’re on, only the stop you left and the time you left it are sent to our server,
        and they are not kept after the trip.
    </p>

    <h3>Notifications</h3>
    <p>
        The app sends you notices you asked for (an event reminder, when to leave for a trip you
        planned, when to get off) and notices about things you set up: a change to a planned trip,
        delays and disruptions on the lines you use, Paperwork dates coming up, weather that
        affects your commute and city notices that affect you. They appear in the app, and as push
        notifications only if you allow them in your browser. For push we store your browser’s
        push subscription (an address and its keys); the message is delivered through your
        browser maker’s push service (for example Google, Mozilla or Apple), which sees only that
        a message is sent, not what it says. We send at most two pushes an hour and five a day,
        and hold non-urgent ones during quiet hours. You can turn push off at any time in the app
        or your browser; turning it off in the app deletes the subscription. Legal basis: contract
        performance (Art. 6(1)(b) GDPR), and for push your consent in the browser (Art. 6(1)(a)
        GDPR).
    </p>
    <p>
        On the iPhone, Expadu uses no push service. When you open the app, it asks our server what
        needs telling in the next 36 hours, and your iPhone schedules those notifications itself:
        when to leave for a trip you set a reminder on or for a routine you confirmed, an event you
        asked to be reminded of, a change to something you planned, and the morning brief if you
        keep it on. A change after you last opened the app (a cancelled tram) reaches you the next
        time you open it. Which kinds you get, and your quiet hours, are set in the app; whether
        Expadu may notify you at all is set in your iPhone’s Settings. Legal basis: contract
        performance (Art. 6(1)(b) GDPR), and your permission on the iPhone.
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
            <strong>Composer.</strong> When you send a message in Composer, your message as you wrote
            it, your earlier messages in that conversation and Composer’s answers to them, your home
            neighbourhood, the current time and the page you asked from are sent to Anthropic (Claude
            Haiku) to answer it. To answer, Composer looks things up and sends what it finds: public
            information (event listings, places from our catalogue with what their listings and
            websites say and, for working there, the anonymous counts of people’s answers about them,
            never who answered; the weather, departures, routes to places you name, public transport
            notices and the titles of our reviewed paperwork guides),
            the plans our planner puts together for you, the names you gave your saved places (Home and
            Work only as such, with their neighbourhood), where you are only as your nearest stop and
            neighbourhood, from your browser’s location or the start you chose, and the changes you ask
            Composer to make (saving a place or an event, a reminder, your plans, Home or Work, a favourite
            stop, how you travel, your alerts and notifications, your step-free need, your profile except
            your email) and whether they were made. When
            it shows you one of your own cards (a trip you’re on, your next plan, your Paperwork
            checklist), it only sends that it is showing one, never what is on it. It never sends your
            coordinates, the addresses of your saved places, your documents or evidence, or anyone
            else’s Paperwork record. If you turned on personalisation, it may also send what you tend
            to like and skip (kinds of places and events, areas and times of day, never place names or
            your history) and your own plans and appointments for today and tomorrow, including those
            recorded in Paperwork, so the suggestions fit you. The line next to the box says so;
            sending is your consent for that one request (Art. 6(1)(a) GDPR), and it lasts 15 minutes.
            Nothing else from your account is sent. Composer keeps the conversation, its answers and
            their cards, in your History until you delete it; a change it makes for you can be undone
            for 24 hours. To find out why an answer went wrong and put it right, we also keep how each
            answer was made for 30 days: your message, what Composer looked up and what it found (never
            your Paperwork record) and the answer it gave (Art. 6(1)(f) GDPR, our legitimate interest in
            answers that are right). It goes when you delete that conversation, your Paperwork record or
            your account, and Download your data includes it.
        </li>
        <li>
            <strong>Composer with your Paperwork.</strong> Composer reads your Paperwork record, and
            can change it, only for a message you allow it on. That is a separate permission with its
            own notice, for that one message (Art. 6(1)(a) GDPR). With it, Composer also sends your
            Paperwork answers (such as your residence title and the dates it keeps), your open
            Paperwork tasks with their steps and dates, and the changes to your Paperwork you ask for
            and what they change in your plan. A change to your answers always waits for you to tap
            Apply; nothing is sent to an authority. The permission lasts 15 minutes, for that message.
            Composer keeps that conversation, with its Paperwork cards, in your History until you
            delete it, and its changes can be undone for 24 hours; deleting your Paperwork record
            removes Composer’s copies of it, and stopping its processing ends the permission. You can
            withdraw the permission in the notice under Composer’s box, and withdrawing Composer’s AI
            withdraws it too.
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
        For Paperwork’s own suggestions and answers, Anthropic’s reply is kept by us for at most
        15 minutes for that request; Composer keeps its conversations as described above. Transfers to the
        USA are covered by the European Commission’s standard contractual clauses in Anthropic’s
        data processing addendum. You can withdraw your consent at any time; it then ends for any
        request not yet sent.
    </p>

    <h3>Personalisation (opt-in)</h3>
    <p>
        If you turn on personalisation in your settings, we learn from what you do in the app:
        which places, events, Today alerts, Travel stops and lines and Composer topics you open,
        search, save, add to your plan, travel to, skip or dismiss, to rank suggestions and put Today in order for
        you (Art. 6(1)(a) GDPR). From Composer we keep only the kind of place and the
        neighbourhood it looked up, never what you wrote; from Travel only the stops and lines
        you picked, never where you are, your Home or an address; nothing from Paperwork. To put
        Today in order, what was learned (the kinds of places and events, the neighbourhoods and
        times of day you lean to, and what you looked at recently) and today's events and open
        places are sent each time to Anthropic PBC (Claude Haiku), an AI service that doesn't use
        them to train its models; its reply is removed after 15 minutes, and a record that it was
        sent, without what it said, after 30 days. What we learn is stored under a pseudonymous
        key, never includes your location or anything you type, and individual records are
        deleted after 90 days. With it on, Composer may also use what you like and your plans
        for today and tomorrow to answer you, as described under AI features. Turning
        personalisation off deletes everything learned and stops all of this at once. It is off
        unless you turn it on; if you turned it on before 26 October 2026, it keeps learning only
        from the places and events you open, save, travel to or turn down until you turn it on
        again.
    </p>
    <p>
        With personalisation on, you can also pick the kinds of event you would go to (such as
        music, tours or kids &amp; family) on Today or on What Expadu knows. We keep your picks
        with your account to put the kinds you picked first on Today until what you open, save
        and go to says more (Art. 6(1)(a) GDPR). They are not sent to the AI service. You can
        change them on What Expadu knows at any time; turning personalisation off deletes them.
        If you turned personalisation on before 18 November 2026, your picks are kept only once
        you turn it on again.
    </p>

    <h3>What Composer remembers</h3>
    <p>
        With personalisation on, you can tell Composer something lasting about yourself, such as
        “I’m vegetarian” or “we have a three-year-old”, or add it yourself on “What Expadu knows”.
        It is kept under a pseudonymous key. With each message you send Composer, what it
        remembers about you, what you tend to like and skip, and your situation and German level
        are sent to Anthropic (Claude Haiku) so its answers fit you; an answer that relied on
        something you told it says so. Expadu never keeps anything about health, religion or
        beliefs, sexuality, politics or union membership: Composer refuses to remember it, and so
        does the screen. You see everything on “What Expadu knows”, can change or forget any of it
        there, and turning personalisation off erases all of it. Legal basis: your consent
        (Art. 6(1)(a) GDPR), given when you turn personalisation on, and again with each Composer
        message.
    </p>

    <h3>Routines (opt-in)</h3>
    <p>
        You can let Expadu learn your routines from the trips you start in the app. This is a
        separate choice from personalisation: it is off unless you turn it on, and it needs
        personalisation on. With it on, for each trip you start we keep where it went (the place’s
        name, or “Home” or “Work”), whether it set off from your Home or Work and nothing else about
        where it started, and when. Trips to health services, places of worship and LGBTQ+ venues
        are never kept. Trips are kept under a pseudonymous key for 90 days. When the same trip
        comes up three times in three weeks at about the same time, the app offers it as a routine
        (“You often go to Work around 08:10”); it never sets one up without your yes. A routine you
        confirm tells you when to leave, and you can change, pause or forget it. Turning routine
        learning off erases the trips and routines kept for it. Legal basis: your consent
        (Art. 6(1)(a) GDPR).
    </p>

    <h3>Today’s line about your day</h3>
    <p>
        The line under the date on Today is written by our own code from what is on your day: your
        plans, appointments and trips, or what’s on in the city. No AI writes it, and nothing is
        sent to anyone for it.
    </p>

    <h3>“Good for laptop work?” answers</h3>
    <p>
        On a place you marked Been there or Saved, you can answer whether it is good for laptop
        work and, if you like, whether it has Wi-Fi, sockets and is quiet. We store your answers
        with your account, the place and when you gave them, so we can show them back to you, let
        you change them and count them (Art. 6(1)(b) GDPR, as part of the service you use). Others
        only ever see anonymous totals, such as “9 of 11 people said yes”, and only once at least
        three people have answered within the last 12 months; never who answered. Composer may
        mention these totals when it describes a place. You can change or withdraw your answer at
        any time on the place’s sheet. Your answers are deleted when you delete your account, and
        in any case 18 months after you gave them.
    </p>

    <h2>7. Retention &amp; deletion</h2>
    <p>
        Account data is kept while your account exists and deleted when you delete your account or
        ask us to. An account whose e-mail address is never verified is deleted after 30 days; a
        week before, we send one reminder to that address. Waitlist entries are kept until you
        withdraw or until the launch notification has been sent; an entry that is never confirmed
        is deleted after 7 days. Location data expires automatically as described above. What
        Composer remembers is kept until you forget it, turn personalisation off or delete your
        account. Trips kept for routines are deleted after 90 days and routines when you forget
        them; both are erased when you turn routine learning or personalisation off. Notifications
        on the iPhone are scheduled on your phone only, and signing out removes them. Server
        logs rotate on a short schedule; the security log is kept for 90 days.
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
