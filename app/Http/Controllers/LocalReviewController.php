<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocalReviewController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($this->isLocalReview($request), 404);

        if (! $request->user()) {
            $email = (string) config('local_review.email');
            abort_unless(str_ends_with($email, '@expadu.test'), 404);

            $reviewer = User::query()
                ->where('email', $email)
                ->where('is_admin', false)
                ->whereNotNull('email_verified_at')
                ->whereNull('two_factor_secret')
                ->firstOrFail();

            Auth::login($reviewer);
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard');
    }

    private function isLocalReview(Request $request): bool
    {
        return app()->environment('local')
            && config('local_review.enabled') === true
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! $request->hasHeader('Forwarded')
            && ! $request->hasHeader('X-Forwarded-For')
            && ! $request->hasHeader('X-Forwarded-Host');
    }
}
