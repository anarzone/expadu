import { AnchoredMenu } from '@/components/ui/anchored-menu';
import { Calendar } from '@/components/ui/date-control';
import { Icon } from '@/components/ui/icon';

/*
 * My Plans' month (owner, 24 Oct 2026: "fix the design of this calendar"):
 * one calendar button at the end of the week strip opens the month as the
 * app's floating panel, above the page and Composer's box, under the button
 * or above it when there's no room, and a bottom sheet on phones. A tap
 * outside or Escape closes it. The week strip stays the day choice; this
 * jumps further. Its look (an ink circle for the chosen day, a dot for
 * today, Today in the header) is Composer's own (composer.css).
 */
export function SharedDate({
    value,
    today,
    onChange,
}: {
    value: string;
    /** The day "Today" jumps to: the server's day, which the sample clock can move. */
    today: string;
    onChange: (value: string) => void;
}) {
    return (
        <AnchoredMenu
            label="Choose a date"
            kind="dialog"
            head={false}
            sheet="phone"
            align="end"
            width={320}
            trigger={(props) => (
                <button
                    {...props}
                    className="composer-week-step composer-week-calendar"
                    aria-label="Choose a date"
                    title="Choose a date"
                >
                    <Icon name="calendar" variant="line" />
                </button>
            )}
        >
            {({ close }) => (
                <div className="composer-calendar">
                    <Calendar
                        value={value}
                        today={today}
                        onPick={(day) => {
                            onChange(day);
                            close();
                        }}
                    />
                </div>
            )}
        </AnchoredMenu>
    );
}
