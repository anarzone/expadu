import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard, login, register } from '@/routes';

const features = [
    {
        emoji: '\u{1F687}',
        title: 'Transit',
        description:
            'Live departures, disruption alerts, and daily commute routines — all in one place.',
    },
    {
        emoji: '\u{1F5FA}\uFE0F',
        title: 'Discover',
        description:
            'Find hidden gems, local spots, and events happening near you right now.',
    },
    {
        emoji: '\u{1F3DB}\uFE0F',
        title: 'Settle',
        description:
            'Navigate bureaucracy, find services, and get settled in your new city with confidence.',
    },
];

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Your City. Your Guide.">
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700&display=swap"
                    rel="stylesheet"
                />
            </Head>

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                {/* Nav */}
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
                    <span className="font-display text-xl font-semibold tracking-tight text-primary">
                        Expadu
                    </span>
                    <nav className="flex items-center gap-3">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-primary-foreground transition hover:bg-accent-hover"
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-lg px-4 py-2 text-sm font-medium text-foreground transition hover:bg-secondary"
                                >
                                    Log in
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-primary-foreground transition hover:bg-accent-hover"
                                    >
                                        Get started
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>

                {/* Hero */}
                <main className="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center lg:py-24">
                    <h1 className="font-display text-5xl leading-tight font-bold tracking-tight text-foreground md:text-6xl lg:text-7xl">
                        Your city.{' '}
                        <span className="text-primary">Your guide.</span>
                    </h1>
                    <p className="mt-5 max-w-lg text-lg leading-relaxed text-muted-foreground md:text-xl">
                        The smart city companion for expats in Germany. Transit,
                        events, local tips — everything you need to feel at
                        home.
                    </p>
                    <div className="mt-10 flex flex-col items-center gap-4 sm:flex-row">
                        {canRegister && (
                            <Link
                                href={register()}
                                className="rounded-xl bg-primary px-8 py-3.5 text-base font-semibold text-primary-foreground shadow-lg transition hover:bg-accent-hover hover:shadow-xl"
                            >
                                Get started free
                            </Link>
                        )}
                        <Link
                            href={login()}
                            className="text-sm font-medium text-muted-foreground transition hover:text-foreground"
                        >
                            Already have an account?{' '}
                            <span className="underline underline-offset-4">
                                Log in
                            </span>
                        </Link>
                    </div>

                    {/* Feature cards */}
                    <section className="mx-auto mt-20 grid w-full max-w-4xl gap-6 md:grid-cols-3">
                        {features.map((feature) => (
                            <div
                                key={feature.title}
                                className="rounded-2xl border border-border bg-card p-8 text-left shadow-sm transition hover:-translate-y-1 hover:shadow-md"
                            >
                                <span className="text-4xl">
                                    {feature.emoji}
                                </span>
                                <h3 className="mt-4 font-display text-lg font-semibold tracking-tight text-foreground">
                                    {feature.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {feature.description}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>

                {/* Footer */}
                <footer className="border-t border-border px-6 py-8 text-center">
                    <p className="text-sm text-muted-foreground">
                        Made for expats in Germany
                    </p>
                </footer>
            </div>
        </>
    );
}
