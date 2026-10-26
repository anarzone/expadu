import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';

/*
 * Settings' building pieces, as the mock's settings-next/app.js draws them:
 * an icon from the line set, a tile that is one button, a labelled
 * field with its error, Save and Cancel, and a status line that fills a
 * moment after it's drawn so screen readers announce it.
 */

export function SettingsIcon({
    name,
    className,
}: {
    name: IconName;
    className?: string;
}) {
    return <Icon name={name} variant="line" className={className ?? ''} />;
}

/** A fact as one button that opens its edit ("Name: Anar. Edit"). */
export function Tile({
    id,
    icon,
    label,
    value,
    editLabel,
    secret,
    extra,
    onOpen,
}: {
    id: string;
    icon?: IconName;
    label: string;
    value: string;
    editLabel: string;
    secret?: boolean;
    extra?: ReactNode;
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            className="settings-tile"
            data-open={id}
            aria-label={
                secret
                    ? `${label}. ${editLabel}`
                    : `${label}: ${value}. ${editLabel}`
            }
            onClick={onOpen}
        >
            {icon ? <SettingsIcon name={icon} /> : null}
            <span className="settings-tile-copy">
                <small>{label}</small>
                <strong className={secret ? 'settings-secret' : undefined}>
                    {value}
                </strong>
            </span>
            {extra}
            <SettingsIcon name="pencil" className="settings-tile-edit" />
        </button>
    );
}

/** A labelled field, its error under it (Laravel's own message). */
export function Field({
    id,
    label,
    error,
    ...input
}: React.InputHTMLAttributes<HTMLInputElement> & {
    id: string;
    label: string;
    error?: string;
}) {
    return (
        <>
            <label className="settings-field" htmlFor={id}>
                {label}
                <input
                    id={id}
                    name={id}
                    aria-describedby={`${id}-error`}
                    aria-invalid={error ? true : undefined}
                    {...input}
                />
            </label>
            <p className="settings-error" id={`${id}-error`} hidden={!error}>
                {error}
            </p>
        </>
    );
}

export function Actions({
    save = 'Save',
    onCancel,
}: {
    save?: string;
    onCancel: () => void;
}) {
    return (
        <div className="settings-actions">
            <button type="submit" className="settings-primary">
                {save}
            </button>
            <button type="button" className="settings-quiet" onClick={onCancel}>
                Cancel
            </button>
        </div>
    );
}

/**
 * A tile opened in place: a form that saves on Enter, cancels on Escape,
 * and on opening puts focus on its first field with the text selected (or,
 * for a choice, the checked one). It stays mounted while a choice changes,
 * so its appear motion doesn't replay.
 */
export function EditForm({
    name,
    children,
    onSave,
    onCancel,
}: {
    name: string;
    children: ReactNode;
    onSave: (form: HTMLFormElement) => void;
    onCancel: () => void;
}) {
    const form = useRef<HTMLFormElement>(null);

    useEffect(() => {
        const first = form.current?.querySelector<HTMLInputElement>(
            'input:not([type="radio"]), input:checked',
        );
        first?.focus();

        if (first && first.type !== 'radio') {
            first.select();
        }
    }, []);

    return (
        <form
            ref={form}
            className="settings-edit"
            data-form={name}
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                onSave(event.currentTarget);
            }}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onCancel();
                }
            }}
        >
            {children}
        </form>
    );
}

/** A panel's status line: drawn empty, filled a moment later so it's announced. */
export function Status({ text }: { text: string }) {
    const [shown, setShown] = useState('');

    useEffect(() => {
        const timer = window.setTimeout(() => setShown(text), 120);

        return () => {
            window.clearTimeout(timer);
            setShown('');
        };
    }, [text]);

    return (
        <p className="settings-status" role="status">
            {shown}
        </p>
    );
}

/** Focus an element once the next frame is drawn. */
export function focusSoon(selector: string) {
    requestAnimationFrame(() =>
        document.querySelector<HTMLElement>(selector)?.focus(),
    );
}
