import { Link, router } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';
import { travel } from '@/routes';
import { store as composerEntry } from '@/routes/composer/entry';

/*
 * The first visit's welcome (the mock's .today-welcome), shown while the
 * person has nothing on Today yet (App\Home\FirstVisit): plan the day in
 * Composer, find a route in Travel, or ask where to start with Paperwork.
 * Prompts go to Composer by POST, so they stay out of the URL and logs.
 */

const today = () =>
    new Date().toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'Europe/Berlin',
    });

const ask = (prompt: string) => router.post(composerEntry.url(), { prompt });

export function TodayWelcome() {
    return (
        <section className="today-welcome" aria-labelledby="welcome-title">
            <div>
                <span className="welcome-symbol">
                    <Icon name="spark" />
                </span>
                <h2 id="welcome-title">What can we help you with?</h2>
                <p>Make a plan, find your way, or get settled.</p>
            </div>
            <div className="welcome-choices">
                <button
                    type="button"
                    onClick={() => ask(`Help me plan my day on ${today()}.`)}
                >
                    <Icon name="plus" />
                    Plan my day
                </button>
                <Link href={travel()}>
                    <Icon name="train" />
                    Find a route
                </Link>
                <button
                    type="button"
                    onClick={() =>
                        ask(
                            'Help me settle in: which paperwork should I sort out first?',
                        )
                    }
                >
                    <Icon name="document" />
                    Help me settle in
                </button>
            </div>
        </section>
    );
}
