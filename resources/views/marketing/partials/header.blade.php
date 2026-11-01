{{-- The site header, shared by every marketing page. On the landing its
     section links stay in the page ($onLanding); elsewhere they lead home. --}}
@php
    $home = ($onLanding ?? false) ? '' : route('home');
@endphp
<header class="xv-wrap xv-nav" @if ($onLanding ?? false) data-reveal="fade" @endif>
    <a class="xv-logo" href="{{ route('home') }}" aria-label="Expadu home">
        <svg viewBox="13 22 74 60" aria-hidden="true"><g transform="translate(2.45 2.05)"><circle cx="20.5" cy="50" r="6" fill="#05badd"/><rect class="xv-logo__stem" x="30" y="24" width="13" height="52" rx="6"/><rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/><rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/><rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/></g></svg>
        <span class="xv-disp">Expadu</span>
    </a>
    <nav class="xv-nav__links" aria-label="Main">
        <a class="xv-navlink" href="{{ $home }}#day">The day</a>
        <a class="xv-navlink" href="{{ $home }}#street">Events</a>
        <a class="xv-navlink" href="{{ $home }}#composer">Composer</a>
        <a class="xv-navlink" href="{{ route('tools.index') }}" @if (request()->routeIs('tools.*')) aria-current="page" @endif>Free tools</a>
        <a class="xv-navlink" href="{{ route('blog.index') }}" @if (request()->routeIs('blog.*')) aria-current="page" @endif>Guides</a>
    </nav>
    <div class="xv-nav__cta">
        <details class="xv-menu">
            <summary aria-label="Menu"><svg class="xv-ico xv-menu__open" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><svg class="xv-ico xv-menu__close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></summary>
            <div class="xv-menu__panel">
                <a href="{{ $home }}#day">The day</a>
                <a href="{{ $home }}#street">Events</a>
                <a href="{{ $home }}#composer">Composer</a>
                <a href="{{ route('tools.index') }}">Free tools</a>
                <a href="{{ route('blog.index') }}">Guides</a>
                @guest
                    <a href="{{ route('login') }}">Log in</a>
                @endguest
            </div>
        </details>
        @auth
            <a class="xv-btn xv-btn--ink xv-btn--sm" href="{{ route('today') }}">Open the app</a>
        @else
            <a class="xv-login" href="{{ route('login') }}">Log in</a>
            <a class="xv-btn xv-btn--ink xv-btn--sm" href="#waitlistForm">Start free</a>
        @endauth
    </div>
</header>
