import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import './auth/account.css';

/** The street behind the poster (account.css's .account-street) is the
    page's largest paint: fetch it with the page, not once the CSS has
    applied. Each file under the media query account.css shows it at, so
    one matches and nothing loads twice. */
const STREET = [
    { href: '/images/account/street.webp', media: '(min-width: 900px)' },
    {
        href: '/images/account/street-strip-2400.webp',
        media: '(max-width: 899px) and (resolution >= 1.85dppx)',
    },
    {
        href: '/images/account/street-strip-1800.webp',
        media: '(max-width: 899px) and (1.25dppx <= resolution < 1.85dppx)',
    },
    {
        href: '/images/account/street-strip-1200.webp',
        media: '(max-width: 899px) and (resolution < 1.25dppx)',
    },
];

/*
 * Sign in and create an account "On the street" (the owner's choice A,
 * prototype/dev/design/account/, 19 Oct 2026): Cologne's street from Events
 * fills the page, and each auth page is a cream poster taped to its wall,
 * with a marker label and a purple heading in the posters' type. Laptops
 * show the whole street with the poster on the wall's open stretch; phones
 * and narrow windows a strip of skyline above it.
 */
export default function AuthLayout({
    children,
    switchTo,
}: {
    children: ReactNode;
    /** The link in the corner to the other way in ("New here? Create an account"). */
    switchTo?: { href: string; lead: string; action: string };
}) {
    return (
        <div className="account-page">
            <Head>
                {STREET.map(({ href, media }) => (
                    <link
                        key={href}
                        rel="preload"
                        as="image"
                        type="image/webp"
                        href={href}
                        media={media}
                        fetchPriority="high"
                    />
                ))}
            </Head>
            <div className="account-street" aria-hidden="true" />
            <header className="account-top">
                <span className="account-brand">
                    <svg viewBox="13 22 74 60" aria-hidden="true">
                        <g transform="translate(2.45 2.05)">
                            <circle cx="20.5" cy="50" r="6" fill="#05badd" />
                            <rect
                                x="30"
                                y="24"
                                width="13"
                                height="52"
                                rx="6"
                                fill="currentColor"
                            />
                            <rect
                                x="46"
                                y="24"
                                width="34"
                                height="13"
                                rx="6"
                                fill="#ff3902"
                                transform="rotate(-7 46 30)"
                            />
                            <rect
                                x="46"
                                y="43.5"
                                width="25"
                                height="13"
                                rx="6"
                                fill="#ff3902"
                                transform="rotate(-4 46 50)"
                            />
                            <rect
                                x="46"
                                y="63"
                                width="34"
                                height="13"
                                rx="6"
                                fill="#ff3902"
                                transform="rotate(-2 46 69)"
                            />
                        </g>
                    </svg>
                    Expadu
                </span>
                {switchTo ? (
                    <Link className="account-switch" href={switchTo.href}>
                        <span>{switchTo.lead}</span>
                        <b>{switchTo.action}</b>
                    </Link>
                ) : null}
            </header>
            {/* Each poster says what happened itself (the shared flash
                toast would say it twice). */}
            <main className="account-poster">{children}</main>
        </div>
    );
}
