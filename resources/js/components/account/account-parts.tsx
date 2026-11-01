import { usePage } from '@inertiajs/react';
import type { ChangeEvent, ReactNode, Ref } from 'react';
import { useState } from 'react';
import { redirect } from '@/routes/social';

/* The parts of the sign-in posters (prototype/dev/design/account/app.js):
   the marker label, a field, the one password field with Show, and the
   line that says what happened. */

export function AccountLabel({ children }: { children: ReactNode }) {
    return <span className="account-label">{children}</span>;
}

type FieldProps = {
    id: string;
    label: string;
    type?: string;
    inputMode?: 'text' | 'numeric' | 'email';
    maxLength?: number;
    autoComplete: string;
    value: string;
    onChange?: (value: string) => void;
    readOnly?: boolean;
    error?: string;
    inputRef?: Ref<HTMLInputElement>;
};

export function AccountField({
    id,
    label,
    type = 'text',
    inputMode,
    maxLength,
    autoComplete,
    value,
    onChange,
    readOnly,
    error,
    inputRef,
}: FieldProps) {
    return (
        <div className="account-field">
            <label htmlFor={id}>{label}</label>
            <div className="account-input">
                <input
                    ref={inputRef}
                    id={id}
                    name={id}
                    type={type}
                    inputMode={inputMode}
                    maxLength={maxLength}
                    autoComplete={autoComplete}
                    required
                    readOnly={readOnly}
                    value={value}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    onChange={(e: ChangeEvent<HTMLInputElement>) =>
                        onChange?.(e.target.value)
                    }
                />
            </div>
            <FieldError id={id} message={error} />
        </div>
    );
}

/** One password field with Show; a new one says its rule (8 characters). */
export function AccountPassword({
    id,
    label,
    autoComplete,
    value,
    onChange,
    forgotHref,
    hint = false,
    error,
    inputRef,
}: FieldProps & { forgotHref?: string; hint?: boolean }) {
    const [shown, setShown] = useState(false);
    const described =
        [hint ? `${id}-hint` : null, error ? `${id}-error` : null]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className="account-field">
            <div className="account-field-head">
                <label htmlFor={id}>{label}</label>
                {forgotHref ? <a href={forgotHref}>Forgot password?</a> : null}
            </div>
            <div className="account-input">
                <input
                    ref={inputRef}
                    id={id}
                    name={id}
                    type={shown ? 'text' : 'password'}
                    autoComplete={autoComplete}
                    required
                    minLength={hint ? 8 : undefined}
                    value={value}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={described}
                    onChange={(e) => onChange?.(e.target.value)}
                />
                <button
                    type="button"
                    aria-pressed={shown}
                    aria-controls={id}
                    onClick={() => setShown((s) => !s)}
                >
                    {shown ? 'Hide' : 'Show'}
                </button>
            </div>
            {hint ? (
                <span className="account-hint" id={`${id}-hint`}>
                    At least 8 characters.
                </span>
            ) : null}
            <FieldError id={id} message={error} />
        </div>
    );
}

function FieldError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <span className="account-error" id={`${id}-error`} role="alert">
            {message}
        </span>
    ) : null;
}

const icons = {
    mail: (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M4 6h16v12H4z M4 7l8 6 8-6"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinejoin="round"
            />
        </svg>
    ),
    done: (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M5 12.5l4.5 4.5L19 7.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    ),
    mismatch: (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle
                cx="12"
                cy="12"
                r="9"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
            />
            <path
                d="M12 7.5v5.5M12 16.5v.5"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
            />
        </svg>
    ),
};

/** What happened: a mismatch in the conflict colours, the rest quiet. */
export function AccountMessage({
    kind = 'mail',
    children,
}: {
    kind?: 'mail' | 'done' | 'mismatch';
    children: ReactNode;
}) {
    return (
        <p
            className={`account-message${kind === 'mail' ? '' : ` is-${kind}`}`}
            role={kind === 'mismatch' ? 'alert' : 'status'}
        >
            {icons[kind]}
            <span>{children}</span>
        </p>
    );
}

/** What the server flashed back, said on the poster (the auth pages have no
    toast): a form sent too often ("Too many requests — please wait…"), or
    a Google sign-in that didn't finish. */
export function AccountFlash() {
    const error = usePage<{ flash?: { error?: string | null } }>().props.flash
        ?.error;

    return error ? (
        <AccountMessage kind="mismatch">{error}</AccountMessage>
    ) : null;
}

/** Continue with Google, then "or with email". Google is the only provider
    (SocialLoginController::PROVIDERS). */
export function AccountSocial({ providers }: { providers: string[] }) {
    if (!providers.includes('google')) {
        return null;
    }

    return (
        <>
            <div className="account-social">
                <a href={redirect.url('google')}>
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle
                            cx="12"
                            cy="12"
                            r="10"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                        />
                        <path
                            d="M12 12h5a5 5 0 1 1-1.5-3.6"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                        />
                    </svg>
                    Continue with Google
                </a>
            </div>
            <p className="account-or">or with email</p>
        </>
    );
}
