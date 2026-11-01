import { useIsDark } from '@/hooks/use-appearance';
import type { Appearance } from '@/lib/appearance';
import { SettingsIcon, Status } from './parts';

/*
 * Appearance (owner, 1 Nov 2026: dark mode, the Espresso palette): System,
 * Light or Dark for this device. A choice takes effect at once, so there is
 * nothing to save; each option shows a small Expadu card in its look.
 */

const LOOKS: { id: Appearance; label: string; note: string }[] = [
    { id: 'system', label: 'System', note: 'Follows this device’s setting' },
    { id: 'light', label: 'Light', note: 'Always light' },
    { id: 'dark', label: 'Dark', note: 'Always dark' },
];

/** The menu's line for Appearance. */
export function appearanceLine(appearance: Appearance, dark: boolean): string {
    return appearance === 'system'
        ? `System · ${dark ? 'dark' : 'light'} now`
        : appearance === 'dark'
          ? 'Dark'
          : 'Light';
}

/** A small card in one look: the header, a card with a title and two lines. */
function Preview() {
    return (
        <span className="settings-look-page">
            <span className="settings-look-bar" />
            <span className="settings-look-card">
                <span className="settings-look-dot" />
                <span className="settings-look-line" />
                <span className="settings-look-line short" />
            </span>
        </span>
    );
}

export function AppearancePanel({
    appearance,
    onChange,
}: {
    appearance: Appearance;
    onChange: (next: Appearance) => void;
}) {
    const dark = useIsDark();

    return (
        <section className="settings-panel" aria-labelledby="appearance-title">
            <div className="settings-head">
                <span className="settings-chip look">
                    <SettingsIcon name="moon" />
                </span>
                <div>
                    <h2 id="appearance-title">Appearance</h2>
                    <p>How Expadu looks on this device.</p>
                </div>
            </div>
            <fieldset className="settings-looks">
                <legend className="sr-only">Appearance</legend>
                {LOOKS.map((look) => (
                    <label
                        key={look.id}
                        className="settings-look"
                        data-look={look.id}
                    >
                        <span
                            className="settings-look-preview"
                            aria-hidden="true"
                        >
                            {look.id === 'system' ? (
                                <>
                                    <span data-half="light">
                                        <Preview />
                                    </span>
                                    <span data-half="dark">
                                        <Preview />
                                    </span>
                                </>
                            ) : (
                                <Preview />
                            )}
                        </span>
                        <span className="settings-look-copy">
                            <input
                                type="radio"
                                name="appearance"
                                value={look.id}
                                checked={appearance === look.id}
                                onChange={() => onChange(look.id)}
                            />
                            <span>
                                <strong>{look.label}</strong>
                                {look.note}
                            </span>
                        </span>
                    </label>
                ))}
            </fieldset>
            <Status
                text={
                    appearance === 'system'
                        ? `${dark ? 'Dark' : 'Light'} now, as this device is.`
                        : ''
                }
            />
        </section>
    );
}
