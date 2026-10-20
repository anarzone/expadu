{{-- The launch waitlist at the foot of every page but the landing (which has
     its own Join section). "Start free" links on these pages land here. --}}
<section class="xv-wl" id="waitlist">
    <div class="xv-wrap xv-wl__grid">
        <div class="xv-wl__copy">
            <h2 class="xv-disp">Get the new Expadu first.</h2>
            <p>The new app opens in Cologne first. Leave your e-mail and you’ll know the day it’s ready.</p>
        </div>
        <form class="xv-form waitlist" id="waitlistForm" method="POST" action="{{ route('waitlist.store') }}">
            @csrf
            <input type="hidden" name="source" value="{{ $source ?? 'footer' }}">
            <label>Email<input type="email" name="email" autocomplete="email" placeholder="you@example.com" required maxlength="255"></label>
            <label>City<input type="text" name="city" value="Cologne" autocomplete="address-level2" placeholder="Your city" required maxlength="120"></label>
            <button class="xv-btn xv-btn--cyan xv-btn--block" type="submit">Notify me</button>
            @include('marketing.partials.waitlist-note')
            <p class="waitlist-msg" role="status" aria-live="polite"></p>
        </form>
    </div>
</section>
