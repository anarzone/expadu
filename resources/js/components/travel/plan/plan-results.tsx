import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { PLATFORM_NOT_KNOWN, sourceLabel } from '@/components/ui/state-notice';
import { cn } from '@/lib/utils';
import { InGermanChip, KvbNoticeLink } from '../kvb-notice';
import { TravelChip } from '../labels';
import { LineBadge } from '../line-badge';
import { lineLook, vehicleOf, vehicleWord } from '../line-look';
import { Slide } from '../slide';
import { TravelIcon } from '../travel-icon';
import type { TravelIconName } from '../travel-icon';
import {
    berlinNow,
    marginOf,
    optionLabel,
    reasonsFor,
    rides,
    spareMin,
    stepsOf,
    word,
} from './plan-model';
import type {
    Check,
    PlanAnswer,
    PlanGone,
    PlanOption,
    PlanTarget,
    Step,
} from './plan-model';

/**
 * Plan a trip's answer (departures-next app.js: renderResults, checksMarkup,
 * planOption, stepsMarkup): what was checked, then the options as one
 * choice, best for you first, each with why and its Journey details.
 */

/**
 * Around here at a stop you get off at: the stop is public, and the time
 * (today only) says what's open when you're there. The page keeps none of it.
 */
function aroundHereHref(stop: NonNullable<Step['stop']>) {
    const there = berlinNow(new Date(stop.at));
    const params = new URLSearchParams({
        near: `${stop.lat.toFixed(5)},${stop.lng.toFixed(5)}`,
        name: stop.name,
    });

    if (there.date === berlinNow().date) {
        params.set('time', there.time);
    }

    return `/explore?${params}`;
}

/**
 * While the ways are asked (owner, 24 Oct 2026: a real loading view): the
 * checks and the option rows in their own shape, bars for their words, and
 * one quiet line saying what's being checked, in the checks' own words.
 * Nothing else dims; the fields stay usable.
 */
/** What Plan a trip checks, as its chips say it. */
const CHECKING: [string, TravelIconName][] = [
    ['Departures', 'clock'],
    ['Notices', 'info'],
    ['Weather', 'sun'],
    ['My day', 'calendar'],
    ['Lifts', 'lift'],
];

export function PlanLoading() {
    return (
        <div className="plan-loading" role="status">
            <div className="plan-checks">
                <div className="plan-checks-toggle">
                    <strong className="plan-loading-line">
                        Checking today’s conditions…
                    </strong>
                    <span className="plan-check-chips">
                        {CHECKING.map(([word, icon]) => (
                            <TravelChip
                                key={word}
                                kind="calm"
                                icon={<TravelIcon name={icon} />}
                                className="plan-skel-chip"
                            >
                                {word}
                            </TravelChip>
                        ))}
                    </span>
                    <span className="plan-checks-more" aria-hidden="true">
                        <span className="plan-skel" style={{ width: 56 }} />
                    </span>
                </div>
            </div>
            <div className="plan-options" aria-hidden="true">
                {[0, 1, 2].map((row) => (
                    <div key={row} className="plan-option">
                        <div className="plan-option-pick">
                            <span className="plan-option-body">
                                <span className="plan-option-head">
                                    <span
                                        className="plan-skel is-chip"
                                        style={{ width: row ? 132 : 108 }}
                                    />
                                    <span
                                        className="plan-skel is-chip"
                                        style={{ width: 58 }}
                                    />
                                </span>
                                {row ? null : (
                                    <span className="plan-option-title">
                                        <span
                                            className="plan-skel is-badge"
                                            style={{ width: 132 }}
                                        />
                                    </span>
                                )}
                                <span className="plan-option-times">
                                    <span
                                        className="plan-skel"
                                        style={{ width: '58%' }}
                                    />
                                </span>
                                <span className="plan-option-reasons">
                                    <span>
                                        <span className="plan-reason-icon" />
                                        <span
                                            className="plan-skel"
                                            style={{ width: '34%' }}
                                        />
                                    </span>
                                    <span>
                                        <span className="plan-reason-icon" />
                                        <span
                                            className="plan-skel"
                                            style={{ width: '42%' }}
                                        />
                                    </span>
                                </span>
                            </span>
                            <span className="details-toggle">
                                <span
                                    className="plan-skel"
                                    style={{ width: 112 }}
                                />
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export function PlanChecks({
    checks,
    onTicket,
    ticketBusy = false,
}: {
    checks: Check[];
    /** "I have a Deutschlandticket" (or not): said here, then planned again. */
    onTicket?: (held: boolean) => void;
    ticketBusy?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <section className="plan-checks" aria-label="What we checked">
            <button
                type="button"
                className="plan-checks-toggle"
                aria-expanded={open}
                aria-controls="plan-checks-detail"
                onClick={() => setOpen((value) => !value)}
            >
                <strong>We checked today’s conditions</strong>
                <span className="plan-check-chips tl-chips">
                    {/* The fare is in the list, not a chip: the chips are today's conditions. */}
                    {checks
                        .filter((check) => !check.ticket)
                        .map((check) => (
                            <TravelChip
                                key={check.title}
                                kind={check.tone}
                                icon={<TravelIcon name={check.icon} />}
                                className={
                                    check.pending ? 'plan-skel-chip' : undefined
                                }
                            >
                                {check.short}
                            </TravelChip>
                        ))}
                </span>
                <span className="plan-checks-more">
                    {open ? 'Hide' : 'Details'}
                    <TravelIcon name="down" />
                </span>
            </button>
            <Slide open={open} id="plan-checks-detail">
                <div className="plan-checks-detail">
                    {checks.map((check) => (
                        <p key={check.title}>
                            <strong>{check.title}</strong>
                            {check.notices ? (
                                check.notices.map((notice, i) => (
                                    <span key={i} className="plan-check-notice">
                                        {notice.lang === 'de' ? (
                                            <InGermanChip
                                                fromKvb={notice.url !== null}
                                            />
                                        ) : null}
                                        <span
                                            lang={
                                                notice.lang === 'de'
                                                    ? 'de'
                                                    : undefined
                                            }
                                        >
                                            {notice.words}
                                        </span>
                                        {notice.url ? (
                                            <>
                                                {' '}
                                                <KvbNoticeLink
                                                    url={notice.url}
                                                />
                                            </>
                                        ) : null}
                                    </span>
                                ))
                            ) : (
                                <span>{check.text}</span>
                            )}
                            {check.ticket && onTicket ? (
                                <button
                                    type="button"
                                    className="plan-ticket-toggle"
                                    aria-pressed={check.ticket.held}
                                    disabled={ticketBusy}
                                    onClick={() =>
                                        onTicket(!check.ticket!.held)
                                    }
                                >
                                    {check.ticket.held
                                        ? 'I don’t have one'
                                        : 'I have a Deutschlandticket'}
                                </button>
                            ) : null}
                        </p>
                    ))}
                </div>
            </Slide>
        </section>
    );
}

function spareChip(option: PlanOption, target: PlanTarget | null) {
    const { journey } = option;

    if (!target) {
        return (
            <TravelChip kind="calm" icon={<TravelIcon name="clock" />}>
                {journey.duration_min} min
            </TravelChip>
        );
    }

    const spare = spareMin(journey, target);

    if (spare < 0) {
        return (
            <TravelChip kind="attention" icon={<TravelIcon name="timer" />}>
                {-spare} min late
            </TravelChip>
        );
    }

    if (spare < marginOf(target)) {
        return (
            <TravelChip kind="attention" icon={<TravelIcon name="timer" />}>
                Only {spare} min spare
            </TravelChip>
        );
    }

    return spare >= 90 ? (
        <TravelChip kind="calm" icon={<TravelIcon name="clock" />}>
            {Math.floor(spare / 60)} h{spare % 60 ? ` ${spare % 60} min` : ''}{' '}
            early
        </TravelChip>
    ) : (
        <TravelChip kind="calm" icon={<TravelIcon name="check" />}>
            {spare} min spare
        </TravelChip>
    );
}

function OptionTitle({ option }: { option: PlanOption }) {
    const { journey } = option;
    const legs = rides(journey);

    if (!legs.length) {
        return (
            <span className="plan-option-title">
                <span className="tl-line is-mode">
                    <TravelIcon
                        name={journey.mode === 'bike' ? 'bike' : 'walk'}
                    />
                    <span>
                        {journey.mode === 'bike' ? 'By bike' : 'On foot'}
                    </span>
                </span>
            </span>
        );
    }

    return (
        <span className="plan-option-title">
            {legs.map((leg, index) => (
                <span key={`${leg.line}-${leg.depart_at}`} className="contents">
                    {index > 0 ? (
                        <span className="plan-then">
                            <TravelIcon name="arrow" />
                        </span>
                    ) : null}
                    <LineBadge
                        line={leg.line ?? vehicleWord(vehicleOf(leg.mode))}
                        kind={vehicleOf(leg.mode)}
                        look={lineLook(leg.line, vehicleOf(leg.mode))}
                        word={index === 0}
                    />
                </span>
            ))}
            {/* One text node, shaped as the mock's is. */}
            <span className="plan-option-at">{`at ${legs[0].depart_time}`}</span>
        </span>
    );
}

const stepIcon: Record<string, TravelIconName> = {
    walk: 'walk',
    bike: 'bike',
    transit: 'train',
    alight: 'train',
    arrive: 'pin',
};

function JourneySteps({
    option,
    destination,
    answer,
    target,
    onBoardStop,
}: {
    option: PlanOption;
    destination: string;
    answer: PlanAnswer;
    target: PlanTarget | null;
    onBoardStop?: (name: string) => void;
}) {
    const steps = stepsOf(
        option.journey,
        destination,
        answer.city,
        target,
        word,
    );
    const legs = rides(option.journey);
    const live = legs.some((leg) => leg.realtime);

    return (
        <div className="plan-option-way">
            <ol className="route-steps">
                {steps.map((step, index) => (
                    <li
                        key={`${step.kind}-${index}`}
                        className={cn('route-step', step.kind)}
                    >
                        <time>{step.time}</time>
                        <span className="step-node">
                            <TravelIcon name={stepIcon[step.kind]} />
                        </span>
                        <div>
                            <strong>{step.title}</strong>
                            <small>{step.detail}</small>
                            {step.kind === 'transit' ? (
                                <TravelChip
                                    kind="unsure"
                                    size="sm"
                                    icon={<TravelIcon name="pin" />}
                                >
                                    {PLATFORM_NOT_KNOWN}
                                </TravelChip>
                            ) : null}
                            {step.stop ? (
                                <a
                                    className="step-nearby"
                                    href={aroundHereHref(step.stop)}
                                >
                                    <TravelIcon name="pin" />
                                    Places nearby
                                    <TravelIcon name="arrow" />
                                </a>
                            ) : null}
                            {step.board && onBoardStop ? (
                                <button
                                    type="button"
                                    className="boarding-stop-link"
                                    aria-label={`Timetable at ${step.board}`}
                                    onClick={() =>
                                        step.board && onBoardStop(step.board)
                                    }
                                >
                                    {step.board}
                                    <TravelIcon name="chevron" />
                                </button>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ol>
            <div className="plan-option-side">
                <p className="route-note">
                    {legs.length
                        ? live
                            ? 'Live times where the feed has them'
                            : sourceLabel({ source: 'scheduled' })
                        : 'Street directions aren’t shown here'}
                </p>
            </div>
        </div>
    );
}

type PlanOptionsProps = {
    options: PlanOption[];
    answer: PlanAnswer;
    target: PlanTarget | null;
    destination: string;
    /** The picked option, for options picked with their round button. */
    selected?: string | null;
    onSelect?: (id: string) => void;
    expanded: Set<string>;
    onToggle: (id: string) => void;
    /**
     * An option's own buttons, at the foot of its open details. With them an
     * option opens in place (Plan a trip: owner, 22 Oct 2026, "Trip options"
     * canvas); without them it's picked with its round button (Get there).
     */
    actions?: (option: PlanOption) => ReactNode;
    /** Whether an option is the way in your plan ("In your plan"). */
    planned?: (option: PlanOption) => boolean;
    /** Cancelled runs, by the option they came before: each a slim struck row there. */
    gone?: Map<string, PlanGone[]>;
    /** A boarding stop's timetable, in Nearby. */
    onBoardStop?: (name: string) => void;
};

/**
 * The options, "Best for you" naming the first when there's a choice: each
 * opens in place with its own buttons (`actions`), or they're one choice
 * picked with round buttons.
 */
export function PlanOptions({
    options,
    answer,
    target,
    destination,
    selected = null,
    onSelect,
    expanded,
    onToggle,
    actions,
    planned,
    gone,
    onBoardStop,
}: PlanOptionsProps) {
    const inPlace = actions !== undefined;

    return (
        <div
            className="plan-options"
            role={inPlace ? 'group' : 'radiogroup'}
            aria-label="Your options"
        >
            {options.map((option, index) => {
                const open = expanded.has(option.id);
                const on = inPlace ? open : option.id === selected;
                const best = index === 0 && options.length > 1;
                const reasons = reasonsFor(
                    option,
                    answer,
                    target,
                    planned?.(option) ?? false,
                );
                const disclosure = `route-disclosure-${option.id}`;
                const body = (
                    <span className="plan-option-body">
                        <span className="plan-option-head">
                            {best ? (
                                <TravelChip
                                    kind="best"
                                    icon={<TravelIcon name="star" />}
                                >
                                    Best for you
                                </TravelChip>
                            ) : (
                                <OptionTitle option={option} />
                            )}
                            {spareChip(option, target)}
                        </span>
                        {best ? <OptionTitle option={option} /> : null}
                        <span className="plan-option-times">
                            Leave <strong>{option.journey.depart_time}</strong>{' '}
                            → there{' '}
                            <strong>{option.journey.arrive_time}</strong>
                        </span>
                        <span className="plan-option-reasons">
                            {reasons.map((reason) => (
                                <span
                                    key={reason.text}
                                    className={`is-${reason.kind}`}
                                >
                                    <span className="plan-reason-icon">
                                        <TravelIcon name={reason.icon} />
                                    </span>
                                    {reason.text}
                                </span>
                            ))}
                        </span>
                    </span>
                );

                return (
                    <Fragment key={option.id}>
                        {gone?.get(option.id)?.map((run) => (
                            // Where it was, struck through; the next one moved up.
                            <p key={run.id} className="plan-gone">
                                <LineBadge
                                    line={run.line}
                                    kind={vehicleOf(run.mode)}
                                />
                                <span className="tl-cancelled">
                                    <s>{run.time}</s> Cancelled
                                </span>
                                <span>Not offered</span>
                            </p>
                        ))}
                        <article
                            className={cn('plan-option', on && 'is-on')}
                            aria-label={`${optionLabel(option)}, leave ${option.journey.depart_time}, there ${option.journey.arrive_time}`}
                        >
                            {inPlace ? (
                                // The whole top opens its details; "Journey
                                // details" under it says so.
                                <button
                                    type="button"
                                    className="plan-option-pick"
                                    aria-expanded={open}
                                    aria-controls={disclosure}
                                    onClick={() => onToggle(option.id)}
                                >
                                    {body}
                                    <span className="details-toggle">
                                        Journey details{' '}
                                        <TravelIcon name="down" />
                                    </span>
                                </button>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        className="plan-option-pick"
                                        role="radio"
                                        aria-checked={on}
                                        tabIndex={on ? 0 : -1}
                                        onClick={() => onSelect?.(option.id)}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key !== 'ArrowDown' &&
                                                event.key !== 'ArrowUp'
                                            ) {
                                                return;
                                            }

                                            event.preventDefault();
                                            const next =
                                                options[
                                                    (index +
                                                        (event.key ===
                                                        'ArrowDown'
                                                            ? 1
                                                            : -1) +
                                                        options.length) %
                                                        options.length
                                                ];
                                            onSelect?.(next.id);
                                            (
                                                event.currentTarget.closest(
                                                    '.plan-options',
                                                ) as HTMLElement | null
                                            )
                                                ?.querySelector<HTMLButtonElement>(
                                                    `[data-option="${next.id}"]`,
                                                )
                                                ?.focus();
                                        }}
                                        data-option={option.id}
                                    >
                                        <span
                                            className="plan-radio"
                                            aria-hidden="true"
                                        />
                                        {body}
                                    </button>
                                    <button
                                        type="button"
                                        className="details-toggle"
                                        aria-expanded={open}
                                        aria-controls={disclosure}
                                        onClick={() => onToggle(option.id)}
                                    >
                                        Journey details{' '}
                                        <TravelIcon name="down" />
                                    </button>
                                </>
                            )}
                            <Slide
                                open={open}
                                id={disclosure}
                                className="route-disclosure"
                                innerClassName="route-disclosure-inner"
                            >
                                <JourneySteps
                                    option={option}
                                    destination={destination}
                                    answer={answer}
                                    target={target}
                                    onBoardStop={onBoardStop}
                                />
                                {actions?.(option)}
                            </Slide>
                        </article>
                    </Fragment>
                );
            })}
        </div>
    );
}
