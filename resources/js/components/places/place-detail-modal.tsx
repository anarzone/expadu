import { CategoryIllustration } from '@/components/places/category-illustration';
import { PlaceRichDetail } from '@/components/places/place-rich-detail';
import type { NavigateTarget } from '@/components/places/place-rich-detail';
import type { Place } from '@/components/places/types';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
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
 * The place detail modal — desktop centred Dialog (with a photo/illustration
 * hero) or mobile bottom sheet, both rendering PlaceRichDetail. Shared by the
 * Places page and the home discovery rails so a card opens the SAME detail,
 * whose "Take me there" hands off to the route sheet.
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
    if (isMobile) {
        return (
            <BottomSheet open onClose={onClose}>
                {/* Bottom padding clears the floating mobile dock */}
                <div className="pb-24">
                    <PlaceRichDetail
                        place={place}
                        meta={meta}
                        showPhoto
                        feedback={feedback}
                        onNavigate={onNavigate}
                        onOpenPlace={onOpenPlace}
                        onBack={onBack}
                        backLabel={backLabel}
                    />
                </div>
            </BottomSheet>
        );
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                className="gap-0 overflow-hidden p-0 sm:max-w-md"
            >
                <DialogTitle className="sr-only">{place.name}</DialogTitle>
                {place.photo_url ? (
                    <div className="relative">
                        <img
                            src={place.photo_url}
                            alt={place.name}
                            className="h-36 w-full object-cover"
                        />
                        {place.photo_attribution && (
                            <span className="absolute right-1.5 bottom-1.5 max-w-[85%] truncate rounded bg-black/55 px-1.5 py-0.5 text-[9px] text-white/85">
                                {place.photo_attribution}
                            </span>
                        )}
                    </div>
                ) : (
                    <CategoryIllustration
                        coarse={place.category}
                        className="h-28 w-full"
                        iconSize={38}
                    />
                )}
                <div className="max-h-[72vh] overflow-y-auto p-5">
                    <PlaceRichDetail
                        place={place}
                        meta={meta}
                        feedback={feedback}
                        onNavigate={onNavigate}
                        onOpenPlace={onOpenPlace}
                        onBack={onBack}
                        backLabel={backLabel}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
