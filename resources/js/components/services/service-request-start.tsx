import { Link, usePage, useRemember } from '@inertiajs/react';
import {
    IconArrowRight,
    IconCalendarEvent,
    IconChevronDown,
    IconMapPin,
    IconSparkles,
    IconWorld,
    IconCopy,
    IconX,
} from '@tabler/icons-react';
import { useState } from 'react';
import { composer } from '@/routes';
import { serviceRequestMessage, serviceRequestPrompt } from './service-request';
import type { ServiceRequest } from './service-request';

const kinds = [
    { value: 'local service', label: 'Something else' },
    { value: 'doctor', label: 'Doctor' },
    { value: 'dentist', label: 'Dentist' },
    { value: 'tax advisor', label: 'Tax advisor' },
    { value: 'lawyer', label: 'Lawyer' },
    { value: 'bank', label: 'Bank' },
];
type Preferences = Omit<ServiceRequest, 'service'>;
const empty: Preferences = { area: '', language: '', insurance: '', notes: '' };
const inputClass =
    'mt-1.5 min-h-11 w-full rounded-xl border border-border bg-card px-3 text-sm focus:border-cyan focus:outline-none';

export function ServiceRequestStart() {
    const userId = usePage().props.auth.user.id;
    const [service, setService] = useRemember(
        'doctor',
        `services-kind-${userId}`,
    );
    const [requests, setRequests] = useRemember<Record<string, Preferences>>(
        {},
        `services-requests-${userId}`,
    );
    const request = requests[service] ?? empty;
    const [copyStatus, setCopyStatus] = useState('');
    const [showAppointmentTip, setShowAppointmentTip] = useState(true);
    async function copyRequest() {
        try {
            await navigator.clipboard.writeText(
                serviceRequestMessage({ service, ...request }),
            );
            setCopyStatus(
                'Request copied. You can share it with a provider yourself.',
            );
        } catch {
            setCopyStatus(
                'Copy is unavailable. Select the request text below to copy it.',
            );
        }
    }
    function change(key: keyof Preferences, value: string) {
        setRequests((previous) => ({
            ...previous,
            [service]: { ...empty, ...previous[service], [key]: value },
        }));
    }

    return (
        <div className="grid min-w-0 grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(260px,.7fr)]">
            <section
                className="min-w-0 rounded-[20px] border border-border bg-card p-4 sm:p-5"
                aria-labelledby="service-request-title"
            >
                <h2
                    id="service-request-title"
                    className="text-xl font-semibold tracking-[-.4px]"
                >
                    What are you looking for?
                </h2>
                <p className="mt-1 text-sm leading-relaxed text-text-2">
                    Choose what matters to you. Every preference is optional.
                </p>
                <div
                    className="mt-4 flex gap-1.5 overflow-x-auto pb-1"
                    aria-label="Type of service"
                >
                    {kinds.map((kind) => (
                        <button
                            type="button"
                            key={kind.value}
                            aria-pressed={service === kind.value}
                            onClick={() => setService(kind.value)}
                            className={`min-h-11 shrink-0 rounded-xl px-3 text-[13px] font-medium transition-colors motion-reduce:transition-none ${service === kind.value ? 'bg-cyan-soft text-cyan-h' : 'bg-secondary text-text-2 hover:bg-cyan-soft/50'}`}
                        >
                            {kind.label}
                        </button>
                    ))}
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <label className="text-[13px] font-medium">
                        <span className="flex items-center gap-1.5">
                            <IconMapPin size={16} className="text-cyan-h" />
                            Area
                        </span>
                        <input
                            className={inputClass}
                            value={request.area}
                            onChange={(event) =>
                                change('area', event.target.value)
                            }
                            maxLength={60}
                            placeholder="Neighbourhood or city"
                        />
                    </label>
                    <label className="text-[13px] font-medium">
                        <span className="flex items-center gap-1.5">
                            <IconWorld size={16} className="text-cyan-h" />
                            Language
                        </span>
                        <input
                            className={inputClass}
                            value={request.language}
                            onChange={(event) =>
                                change('language', event.target.value)
                            }
                            maxLength={40}
                            placeholder="Your preferred language"
                        />
                    </label>
                </div>
                <details className="mt-3 border-t border-border pt-1">
                    <summary className="flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm font-medium text-text-2">
                        More preferences
                        <IconChevronDown size={16} />
                    </summary>
                    <div className="grid gap-3 pb-3">
                        <label className="text-[13px] font-medium">
                            Insurance preference
                            <input
                                className={inputClass}
                                value={request.insurance}
                                onChange={(event) =>
                                    change('insurance', event.target.value)
                                }
                                maxLength={60}
                                placeholder="If relevant"
                            />
                        </label>
                        <label className="text-[13px] font-medium">
                            Anything else?
                            <textarea
                                className={`${inputClass} py-2.5`}
                                value={request.notes}
                                onChange={(event) =>
                                    change('notes', event.target.value)
                                }
                                maxLength={120}
                                placeholder="For example, a time of day that works for you"
                                rows={2}
                            />
                        </label>
                    </div>
                </details>
                <label className="mt-3 block text-[13px] font-medium">
                    Your request
                    <textarea
                        readOnly
                        aria-label="Your request"
                        value={serviceRequestMessage({ service, ...request })}
                        rows={3}
                        className={`${inputClass} py-2.5`}
                    />
                </label>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={() => void copyRequest()}
                        className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover"
                    >
                        <IconCopy size={18} /> Copy request
                    </button>
                    <Link
                        href={composer({
                            query: {
                                prompt: serviceRequestPrompt({
                                    service,
                                    ...request,
                                }),
                            },
                        })}
                        className="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-cyan-h transition-colors hover:bg-cyan-soft"
                    >
                        <IconSparkles size={18} />
                        Prepare in Composer
                        <IconArrowRight size={17} />
                    </Link>
                </div>
                {copyStatus && (
                    <p role="status" className="mt-2 text-xs text-text-2">
                        {copyStatus}
                    </p>
                )}
                <p className="mt-2 text-xs leading-relaxed text-text-2">
                    Review the message in Composer. Nothing is sent or booked
                    when you open it.
                </p>
            </section>
            {showAppointmentTip && (
                <aside className="relative rounded-[20px] border border-border bg-card p-4 sm:p-5">
                    <button
                        type="button"
                        aria-label="Dismiss appointment tip"
                        onClick={() => setShowAppointmentTip(false)}
                        className="absolute top-1 right-1 flex size-11 items-center justify-center rounded-xl text-text-2 hover:bg-secondary"
                    >
                        <IconX size={16} />
                    </button>
                    <IconCalendarEvent size={23} className="text-cyan-h" />
                    <h2 className="mt-3 text-lg font-semibold">
                        Already have an appointment?
                    </h2>
                    <p className="mt-1 text-sm leading-relaxed text-text-2">
                        Bring its actual date, time and location into your day
                        once it is arranged.
                    </p>
                    <Link
                        href={composer({
                            query: {
                                prompt: 'I have an appointment already arranged. Help me add its actual date, start and end time, and location to my day.',
                            },
                        })}
                        className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-xl border border-cyan-bd bg-card px-3 text-sm font-semibold text-cyan-h"
                    >
                        Open in Composer
                        <IconArrowRight size={17} />
                    </Link>
                </aside>
            )}
        </div>
    );
}
