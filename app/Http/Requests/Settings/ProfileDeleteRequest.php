<?php

namespace App\Http\Requests\Settings;

use App\Auth\EmailConfirmation;
use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Deleting the account asks for the password, or, for an account made with
 * Google that has none, the code just emailed to it (EXP-174).
 */
class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return EmailConfirmation::applies($this->user())
            ? ['code' => ['required', 'string', 'max:20']]
            : ['password' => $this->currentPasswordRules()];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! EmailConfirmation::applies($this->user()) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $code = (string) preg_replace('/\D/', '', (string) $this->input('code'));
            if (! app(EmailConfirmation::class)->confirm($this, $this->user(), $code)) {
                $validator->errors()->add('code', EmailConfirmation::NOT_ACCEPTED);
            }
        }];
    }
}
