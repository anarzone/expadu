/**
 * Bounds for the onboarding date inputs.
 *
 * Every date field shipped with no `min`/`max`, so QA could enter a visa
 * expiring in 1990 and an arrival in 2999 — and those values feed deadline
 * calculations. The bounds below are deliberately generous: they stop absurd
 * values and typos without blocking real situations.
 */
function shift(years: number): string {
    const d = new Date();
    d.setFullYear(d.getFullYear() + years);

    return d.toISOString().slice(0, 10);
}

export function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * Visa / residence-title expiry. A permit that has ALREADY expired is a real
 * and urgent situation, so the past stays reachable — just not by decades.
 */
export const EXPIRY_BOUNDS = {
    get min() {
        return shift(-5);
    },
    get max() {
        return shift(20);
    },
};

/** Arrival in Germany. The backend enforces `before_or_equal:today`. */
export const ARRIVAL_BOUNDS = {
    get min() {
        return shift(-50);
    },
    get max() {
        return today();
    },
};

/**
 * Move-in date. Not capped at today: someone who has not arrived yet can still
 * know the date their tenancy starts, and the backend accepts it.
 */
export const MOVE_IN_BOUNDS = {
    get min() {
        return shift(-50);
    },
    get max() {
        return shift(1);
    },
};
