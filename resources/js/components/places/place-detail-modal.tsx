import { ItemDetailShell } from '@/components/details/item-detail-shell';
import { PlaceRichDetail } from '@/components/places/place-rich-detail';
import type { NavigateTarget } from '@/components/places/place-rich-detail';
import type { Place } from '@/components/places/types';
import type {
    FeedbackAction,
    FeedbackRating,
    FeedbackState,
} from '@/hooks/use-feedback';

type Feedback = {
    state: FeedbackState | null;
    rating: FeedbackRating | null;
    onAction: (action: FeedbackAction, rating?: FeedbackRating) => void;
};

/**
 * Shared place-detail entry point. The shell owns desktop/mobile presentation;
 * PlaceRichDetail owns the explanatory content and planning rail.
 */
export function PlaceDetailModal({
    place,
    isMobile,
    meta,
    feedback,
    onClose,
    onNavigate,
    onOpenPlace,
    onBack,
    backLabel,
}: {
    place: Place;
    isMobile: boolean;
    meta: string;
    feedback?: Feedback;
    onClose: () => void;
    onNavigate: (target: NavigateTarget) => void;
    onOpenPlace?: (place: Place) => void;
    onBack?: () => void;
    backLabel?: string;
}) {
    return (
        <ItemDetailShell
            kind="place"
            isMobile={isMobile}
            breadcrumb={place.fine_label ?? place.category.replaceAll('_', ' ')}
            title={place.name}
            onClose={onClose}
        >
            <PlaceRichDetail
                place={place}
                meta={meta}
                feedback={feedback}
                onNavigate={onNavigate}
                onOpenPlace={onOpenPlace}
                onBack={onBack}
                backLabel={backLabel}
            />
        </ItemDetailShell>
    );
}
