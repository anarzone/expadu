import { lineIcons, travelIcons } from '@/components/ui/icon-paths';

export type TravelIconName = keyof typeof travelIcons | keyof typeof lineIcons;

/**
 * Travel's own icon set (departures-next/icons.js), falling back to the line
 * set. Unlike the app's Icon it carries no size: Travel's stylesheets size
 * each icon where it sits, as the mock does. Its line (travel.css
 * `.travel-icon`) is its own, so it draws the same on any page. Static
 * artwork only.
 */
export function TravelIcon({ name }: { name: TravelIconName }) {
    const body =
        (travelIcons as Record<string, string>)[name] ??
        (lineIcons as Record<string, string>)[name] ??
        '';

    return (
        <svg
            className="travel-icon"
            viewBox="0 0 24 24"
            aria-hidden="true"
            focusable="false"
            dangerouslySetInnerHTML={{ __html: body }}
        />
    );
}
