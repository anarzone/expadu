{{-- Traps for scripts posting strangers' addresses (WaitlistGuard): a field
     people never see, and when this form was drawn. --}}
<div class="xv-form__trap" aria-hidden="true">
    <label>Leave this empty<input type="text" name="{{ \App\Marketing\WaitlistGuard::Trap }}" tabindex="-1" autocomplete="off"></label>
</div>
<input type="hidden" name="{{ \App\Marketing\WaitlistGuard::Stamp }}" value="{{ \App\Marketing\WaitlistGuard::stamp() }}">
