import { Form, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { clearPrivateBrowserData } from '@/lib/private-browser-data';
import { send } from '@/routes/email-confirmation';
import type { Auth } from '@/types';

export default function DeleteUser() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const passwordInput = useRef<HTMLInputElement>(null);
    // An account made with Google has no password: it confirms with a code.
    const [codeSent, setCodeSent] = useState(false);
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState<string>();

    function sendCode() {
        setSending(true);
        setSendError(undefined);
        router.post(
            send.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setCodeSent(true),
                onError: (errors) => setSendError(errors.code),
                onFinish: () => setSending(false),
            },
        );
    }

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Delete account"
                description="Delete your account and all of its resources"
            />
            <div className="space-y-4 rounded-lg border border-conflict-border bg-conflict-bg p-4">
                <div className="relative space-y-0.5 text-conflict-text">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">
                        Please proceed with caution, this cannot be undone.
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            Delete account
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            Are you sure you want to delete your account?
                        </DialogTitle>
                        <DialogDescription>
                            Once your account is deleted, all of its resources
                            and data will also be permanently deleted.{' '}
                            {auth.has_password
                                ? 'Please enter your password to confirm you would like to permanently delete your account.'
                                : `To confirm, enter the code we email to ${auth.user.email}.`}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            onSuccess={() => {
                                router.clearHistory();
                                void clearPrivateBrowserData();
                            }}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    {auth.has_password ? (
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor="password"
                                                className="sr-only"
                                            >
                                                Password
                                            </Label>

                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                ref={passwordInput}
                                                placeholder="Password"
                                                autoComplete="current-password"
                                            />

                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>
                                    ) : codeSent ? (
                                        <div className="grid gap-2">
                                            <Label htmlFor="code">
                                                Code from your email
                                            </Label>

                                            <Input
                                                id="code"
                                                name="code"
                                                inputMode="numeric"
                                                maxLength={6}
                                                autoComplete="one-time-code"
                                                required
                                            />

                                            <InputError message={errors.code} />

                                            <Button
                                                type="button"
                                                variant="link"
                                                className="justify-self-start px-0"
                                                disabled={sending}
                                                onClick={sendCode}
                                            >
                                                Send a new code
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="grid gap-2">
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                className="justify-self-start"
                                                disabled={sending}
                                                onClick={sendCode}
                                            >
                                                Email me a code
                                            </Button>

                                            <InputError message={sendError} />
                                        </div>
                                    )}

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={
                                                processing ||
                                                (!auth.has_password &&
                                                    !codeSent)
                                            }
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                Delete account
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
