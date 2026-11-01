import { Head } from '@inertiajs/react';
import { IconFirstAidKit } from '@tabler/icons-react';
import { ServiceRequestStart } from '@/components/services/service-request-start';
import { ICON_STROKE } from '@/constants/icons';
import AppLayout from '@/layouts/app-layout';

/**
 * Launch gate: the services directory isn't ready yet (provider search waits
 * for the post-launch Services page, mock services-next), so the page shows a
 * coming-soon screen where a request can still be prepared.
 */
export default function Services() {
    return (
        <AppLayout fullWidth rightPanel={null}>
            <Head title="Services" />
            <div className="mx-auto w-full max-w-[1040px] px-4 pt-4 pb-24 md:px-8 md:pt-6">
                <header className="mb-5 flex items-start gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-primary">
                        <IconFirstAidKit size={23} stroke={ICON_STROKE} />
                    </span>
                    <div>
                        <h1 className="text-[28px] font-semibold tracking-[-.8px]">
                            Services
                        </h1>
                        <p className="mt-1 text-sm text-text-2">
                            A little help with everyday life.
                        </p>
                    </div>
                </header>
                <p className="mb-4 max-w-[680px] text-sm leading-relaxed text-text-2">
                    Provider search is not connected yet. You can prepare what
                    you need or organise an appointment you have already
                    arranged.
                </p>
                <ServiceRequestStart />
            </div>
        </AppLayout>
    );
}
