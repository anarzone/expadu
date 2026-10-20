import type { CSSProperties } from 'react';
import type { PlannedTrip } from '@/types/day';
import { lineLook, vehicleOf } from '../line-look';
import { TravelIcon } from '../travel-icon';
import type { PlanTarget } from './plan-model';

/**
 * "Added to your plan" (departures-next app.js savedPlan): the way is on
 * your day, Leave now leads with it, and "Remind me when to leave" pushes
 * five minutes before it goes. Change the plan goes back to the options.
 */

type PlanSavedProps = {
    planned: PlannedTrip;
    /** "Tram 4, no change" */
    title: string;
    target: PlanTarget | null;
    desktop: boolean;
    remindBusy: boolean;
    /** Why the reminder couldn't be switched on (notifications not allowed). */
    remindNote: string | null;
    onRemind: () => void;
    onDay: () => void;
    onChange: () => void;
    onDone: () => void;
};

export function PlanSaved({
    planned,
    title,
    target,
    desktop,
    remindBusy,
    remindNote,
    onRemind,
    onDay,
    onChange,
    onDone,
}: PlanSavedProps) {
    const look = planned.ride
        ? lineLook(planned.ride.line, vehicleOf(planned.ride.mode))
        : null;

    return (
        <section className="plan-saved" aria-labelledby="plan-saved-title">
            <span
                className="plan-saved-mark"
                style={
                    look
                        ? ({
                              '--line': look.colour,
                              '--ink': look.ink,
                          } as CSSProperties)
                        : undefined
                }
            >
                <TravelIcon name="check" />
            </span>
            <h2 id="plan-saved-title" tabIndex={-1}>
                Added to your plan
            </h2>
            <p className="plan-saved-summary">
                {`${title} · leave ${planned.depart_time} · there ${planned.arrive_time}`}
            </p>
            <ul className="plan-saved-list">
                <li>
                    <button type="button" onClick={onDay}>
                        <span className="plan-saved-icon">
                            <TravelIcon name="calendar" />
                        </span>
                        <span>
                            <strong>It’s on My day</strong>
                            <small>
                                {desktop
                                    ? `Travel shows it as your next trip${target ? `, between now and your ${target.start_time}` : ''}`
                                    : target
                                      ? `Between now and your ${target.start_time}`
                                      : 'With the rest of today'}
                            </small>
                        </span>
                        <TravelIcon name="chevron" />
                    </button>
                </li>
                <li>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={planned.remind}
                        disabled={remindBusy}
                        onClick={onRemind}
                    >
                        <span className="plan-saved-icon">
                            <TravelIcon name="bell" />
                        </span>
                        <span>
                            <strong>Remind me when to leave</strong>
                            <small>
                                {planned.remind
                                    ? `At ${planned.remind_at}, five minutes before you need to go.`
                                    : (remindNote ?? 'Off')}
                            </small>
                        </span>
                        <span className="plan-switch" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <div className="plan-saved-actions">
                <button
                    type="button"
                    className="quiet-button"
                    onClick={onChange}
                >
                    Change the plan
                </button>
                <button
                    type="button"
                    className="primary is-ink"
                    onClick={onDone}
                >
                    Done
                </button>
            </div>
        </section>
    );
}
