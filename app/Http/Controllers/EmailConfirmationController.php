<?php

namespace App\Http\Controllers;

use App\Auth\EmailConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Confirm it's you with an emailed code, for an account without a password
 * (EmailConfirmation). It answers like Fortify's confirm-password: JSON for
 * the sheets that ask in place, a redirect back to where the person was going
 * for the confirm page.
 */
class EmailConfirmationController extends Controller
{
    public function send(Request $request, EmailConfirmation $confirmation): Response|RedirectResponse
    {
        abort_unless(EmailConfirmation::applies($request->user()), 403, 'This account confirms with its password.');

        if (! $confirmation->send($request, $request->user())) {
            throw ValidationException::withMessages(['code' => 'We couldn’t send the code. Try again in a minute.']);
        }

        return $request->wantsJson()
            ? response()->noContent(202)
            : back()->with('status', 'confirmation-code-sent');
    }

    public function confirm(Request $request, EmailConfirmation $confirmation): JsonResponse|RedirectResponse
    {
        abort_unless(EmailConfirmation::applies($request->user()), 403, 'This account confirms with its password.');

        $request->validate(['code' => ['required', 'string', 'max:20']]);
        // "123 456" and "123-456" are the same code.
        $code = (string) preg_replace('/\D/', '', (string) $request->input('code'));

        if (! $confirmation->confirm($request, $request->user(), $code)) {
            throw ValidationException::withMessages(['code' => EmailConfirmation::NOT_ACCEPTED]);
        }

        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->to($this->intended($request));
    }

    /**
     * Where password.confirm sent the person from, on this site only;
     * anything else goes home.
     */
    private function intended(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');
        $origin = $request->getSchemeAndHttpHost();

        return is_string($intended) && ($intended === $origin || str_starts_with($intended, $origin.'/'))
            ? $intended
            : (string) config('fortify.home', '/dashboard');
    }
}
