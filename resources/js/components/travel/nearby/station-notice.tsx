import { InGermanChip, KvbNoticeLink } from '../kvb-notice';
import { TravelBanner } from '../labels';
import { TravelIcon } from '../travel-icon';
import { noticeWords } from './types';
import type { Station } from './types';

/**
 * A KVB notice for a station's lines, as Travel's Nearby shows it: our
 * plain-English brief when one is written, else KVB's own German words
 * marked as such, the lines it's about, and KVB's page behind the link.
 * Today's Check departures shows the same piece (Travel's UI everywhere).
 */
export function StationNotice({
    notice,
}: {
    notice: Station['notices'][number];
}) {
    const { headline, text, lang } = noticeWords(notice);
    const kvb = notice.source?.startsWith('kvb') ?? false;
    // Every notice is KVB's; its feed's own name ("kvb-betriebslage") isn't one to show.
    const lines = `Line${notice.lines.length === 1 ? '' : 's'} ${notice.lines.join(', ')}${kvb ? ' · KVB' : ''}`;
    const german = lang === 'de';

    return (
        <TravelBanner
            kind={
                notice.severity === 'critical' || notice.severity === 'major'
                    ? 'conflict'
                    : 'attention'
            }
            icon={<TravelIcon name="alert" />}
            title={
                german ? (
                    <>
                        <InGermanChip fromKvb={kvb} />
                        <span lang="de">{headline}</span>
                    </>
                ) : (
                    headline
                )
            }
        >
            {/* KVB's own words keep to the line, after its lines; all of them are behind the link. */}
            {german ? (
                <span className="kvb-notice-line">
                    {lines}
                    {text ? (
                        <>
                            {' · '}
                            <span lang="de">{text}</span>
                        </>
                    ) : null}
                </span>
            ) : text ? (
                `${text} · ${lines}`
            ) : (
                lines
            )}
            {notice.url ? (
                <KvbNoticeLink url={notice.url} className="is-own-line" />
            ) : null}
        </TravelBanner>
    );
}
