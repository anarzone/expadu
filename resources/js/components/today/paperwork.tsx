import { Link } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { formatDate, kindLabels, onDay } from '@/components/paperwork/format';
import { Icon } from '@/components/ui/icon';
import { bureaucracy } from '@/routes';

/*
 * Today's Paperwork (the mock's .deadline-strip and .paperwork-panel), from
 * the reviewed v2 plan (App\Home\TodayPaperwork): the urgent date, the next
 * step and how many required documents are ready. The words are
 * Paperwork's own ("Visa expires tomorrow"), never a legal claim of
 * Today's.
 */

type Documents = { ready: number; total: number } | null;
type ProcessLink = { process: string | null; step: string | null };

export type TodayPaperwork = {
    strip: {
        kind:
            | 'document_expiry'
            | 'legal_due'
            | 'authority_follow_up'
            | 'preparation_target';
        document: string | null;
        title: string;
        date: string;
        days_remaining: number;
        urgency: 'overdue' | 'critical';
        next: string | null;
        documents: Documents;
        link: ProcessLink;
    } | null;
    processes: Record<
        string,
        {
            title: string | null;
            documents: Documents;
            occurrence_key: string | null;
        }
    >;
    focus: {
        process_title: string | null;
        title: string;
        documents: Documents;
        link: ProcessLink;
    } | null;
} | null;

const documentNames: Record<string, string> = {
    visa: 'Visa',
    residence_title: 'Residence permit',
    passport: 'Passport',
};

/** Paperwork's own page for a process and its step. */
const processHref = (link: ProcessLink) =>
    bureaucracy({
        query: link.process
            ? {
                  view: 'detail',
                  process: link.process,
                  ...(link.step ? { step: link.step } : {}),
              }
            : {},
    });

const ready = (documents: Documents) =>
    documents
        ? `${documents.ready} of ${documents.total} documents ready`
        : null;

/** The urgent date above the day: "Visa expires tomorrow". */
export function DeadlineStrip({
    strip,
}: {
    strip: NonNullable<TodayPaperwork>['strip'];
}) {
    if (!strip) {
        return null;
    }

    const when =
        strip.days_remaining < 0
            ? `was ${formatDate(strip.date)}`
            : onDay(strip.days_remaining, strip.date);
    const headline =
        strip.kind === 'document_expiry'
            ? `${documentNames[strip.document ?? ''] ?? 'A document'} ${strip.days_remaining < 0 ? 'expired' : 'expires'} ${when.replace(/^was /, 'on ')}`
            : `${kindLabels[strip.kind]} ${when}`;
    const summary = [
        strip.kind === 'document_expiry' ? strip.next : strip.title,
        ready(strip.documents),
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <Link
            className="deadline-strip"
            href={processHref(strip.link)}
            aria-label={`${headline}. ${summary}. Open it in Paperwork`}
        >
            <span className="deadline-symbol" aria-hidden="true">
                <Icon name="clock" />
            </span>
            <span className="deadline-copy">
                <strong>{headline}</strong>
                {summary && <small>{summary}</small>}
            </span>
            <Icon name="arrow" />
        </Link>
    );
}

/** The Paperwork panel: the next step and its documents. */
export function PaperworkPanel({
    focus,
}: {
    focus: NonNullable<TodayPaperwork>['focus'];
}) {
    if (!focus) {
        return null;
    }

    const documents = focus.documents;

    return (
        <section className="paperwork-panel" aria-labelledby="paperwork-title">
            <div className="section-head">
                <h2 id="paperwork-title">Paperwork</h2>
                <div className="section-tools">
                    <span className="record-label">Your records</span>
                </div>
            </div>
            <Link className="paperwork-focus" href={processHref(focus.link)}>
                <div className="paperwork-copy">
                    <h3>{focus.process_title ?? 'Your next step'}</h3>
                    <p>{focus.title}</p>
                </div>
                {documents && (
                    <div
                        className="document-progress"
                        style={
                            {
                                '--progress': `${Math.round((documents.ready / documents.total) * 100)}%`,
                            } as CSSProperties
                        }
                        aria-label={ready(documents) ?? undefined}
                    >
                        <strong>
                            {documents.ready}
                            <small>{` / ${documents.total}`}</small>
                        </strong>
                        <span>ready</span>
                    </div>
                )}
            </Link>
            <div className="paperwork-followups">
                <Link
                    className="situation-link"
                    href={bureaucracy({ query: { guide: 'details' } })}
                >
                    Your situation
                    <span>Review details</span>
                </Link>
            </div>
        </section>
    );
}

/** What to bring for an appointment from Paperwork (the mock's .agenda-paperwork). */
export function WhatToBring({
    process,
}: {
    process: NonNullable<TodayPaperwork>['processes'][string] | undefined;
}) {
    if (!process?.documents || !process.occurrence_key) {
        return null;
    }

    const label = ready(process.documents);

    return (
        <Link
            className="agenda-paperwork"
            href={bureaucracy({
                query: { view: 'paperwork', process: process.occurrence_key },
            })}
            aria-label={`What to bring for ${process.title ?? 'this appointment'}: ${label}`}
        >
            <Icon name="document" />
            <span>
                <small>What to bring · {process.title}</small>
                <strong>{label}</strong>
            </span>
            <Icon name="arrow" />
        </Link>
    );
}

/** No Paperwork record yet: the optional way in (the mock's .paperwork-optional). */
export function SettlingIn() {
    return (
        <section
            className="paperwork-panel paperwork-optional"
            aria-label="Optional settling-in help"
        >
            <Link href={bureaucracy()}>
                <Icon name="document" />
                <span>
                    <strong>Settling in?</strong>
                    <small>Get started when you’re ready</small>
                </span>
            </Link>
        </section>
    );
}
