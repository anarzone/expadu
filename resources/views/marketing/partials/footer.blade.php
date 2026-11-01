{{-- The site footer, shared by every marketing page. --}}
@php
    $home = ($onLanding ?? false) ? '' : route('home');
@endphp
<footer class="xv-footer">
    <div class="xv-wrap">
        <div class="xv-footer__grid">
            <div class="xv-footer__brand">
                <a class="xv-logo xv-logo--light" href="{{ route('home') }}" aria-label="Expadu home">
                    <svg viewBox="13 22 74 60" aria-hidden="true"><g transform="translate(2.45 2.05)"><circle cx="20.5" cy="50" r="6" fill="#05badd"/><rect class="xv-logo__stem" x="30" y="24" width="13" height="52" rx="6"/><rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/><rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/><rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/></g></svg>
                    <span class="xv-disp">Expadu</span>
                </a>
                <p>The daily companion for life in a new city. Starting with Cologne.</p>
            </div>
            <nav class="xv-footer__links" aria-label="Footer">
                <div>
                    <strong>Product</strong>
                    <a href="{{ $home }}#day">Today</a>
                    <a href="{{ $home }}#street">Events</a>
                    <a href="{{ $home }}#day">Paperwork</a>
                    <a href="{{ $home }}#day">Travel</a>
                    <a href="{{ $home }}#composer">Composer</a>
                </div>
                <div>
                    <strong>Learn</strong>
                    <a href="{{ route('blog.index') }}">Guides</a>
                    <a href="{{ route('tools.index') }}">Free tools</a>
                    <a href="{{ $home }}#trust">How we check facts</a>
                    <a href="{{ $home }}#faq">FAQ</a>
                </div>
                <div>
                    <strong>Account</strong>
                    @auth
                        <a href="{{ route('today') }}">Open the app</a>
                    @else
                        <a href="{{ route('login') }}">Log in</a>
                        <a href="#waitlistForm">Start free</a>
                    @endauth
                </div>
                <div>
                    <strong>Legal</strong>
                    <a href="{{ route('impressum') }}">Impressum</a>
                    <a href="{{ route('datenschutz') }}">Datenschutz</a>
                </div>
            </nav>
        </div>
        <div class="xv-footer__base">
            <span>© {{ date('Y') }} Expadu · Made in Cologne</span>
            <span>{{ $note ?? 'Expadu isn’t legal advice. Place data © OpenStreetMap contributors.' }}</span>
        </div>
    </div>
</footer>
