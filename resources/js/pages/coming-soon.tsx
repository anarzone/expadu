import { Head, Link } from '@inertiajs/react';
import { IconArrowLeft } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/app-layout';

interface ComingSoonProps {
    title: string;
    description: string;
}

export default function ComingSoon({ title, description }: ComingSoonProps) {
    return (
        <AppLayout>
            <Head title={`${title} — Coming soon`} />
            <div className="flex min-h-[60vh] flex-col items-center justify-center px-6 py-16 text-center">
                <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold tracking-wide text-primary uppercase">
                    Coming soon
                </span>
                <h1 className="mt-4 font-display text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                    {title}
                </h1>
                <p className="mt-3 max-w-md text-base leading-relaxed text-muted-foreground">
                    {description}
                </p>
                <Link
                    href="/dashboard"
                    className="mt-8 inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition hover:bg-accent-hover"
                >
                    <IconArrowLeft size={16} />
                    Back to dashboard
                </Link>
            </div>
        </AppLayout>
    );
}
