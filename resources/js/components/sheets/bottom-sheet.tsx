import { useEffect, useRef  } from 'react';
import type {ReactNode} from 'react';

/**
 * Bottom sheet ported from prototype's drag logic:
 * - Fixed at bottom, 600px wide (100% on mobile), 20px rounded top
 * - Drag up to full screen, drag down to close
 * - Close: >60px down or velocity >0.3
 * - Spring: cubic-bezier(.32,1,.4,1)
 * - Overlay backdrop, body scroll lock
 */
export function BottomSheet({
    open,
    onClose,
    children,
}: {
    open: boolean;
    onClose: () => void;
    children: ReactNode;
}) {
    const sheetRef = useRef<HTMLDivElement>(null);
    const handleRef = useRef<HTMLDivElement>(null);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    // Imperative drag — ported from prototype
    useEffect(() => {
        const handle = handleRef.current;
        const sheet = sheetRef.current;

        if (!handle || !sheet) {
return;
}

        let dragging = false;
        let startY = 0;
        let startTime = 0;
        let lastY = 0;
        let startH = 0;

        function onStart(e: MouseEvent | TouchEvent) {
            dragging = true;
            startY = 'touches' in e ? e.touches[0].clientY : e.clientY;
            lastY = startY;
            startTime = Date.now();
            startH = sheet!.offsetHeight;
            sheet!.style.transition = 'none';
            handle!.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        }

        function onMove(e: MouseEvent | TouchEvent) {
            if (!dragging) {
return;
}

            lastY = 'touches' in e ? e.touches[0].clientY : e.clientY;
            const delta = startY - lastY; // up = positive = taller
            const newH = Math.max(
                100,
                Math.min(window.innerHeight, startH + delta),
            );
            sheet!.style.height = newH + 'px';
            e.preventDefault();
        }

        function onEnd() {
            if (!dragging) {
return;
}

            dragging = false;
            handle!.style.cursor = 'grab';
            document.body.style.userSelect = '';

            const currentH = sheet!.offsetHeight;
            const vel = (startY - lastY) / Math.max(1, Date.now() - startTime);

            // Close if dragged down enough or fast enough
            if (currentH < 200 || vel < -0.3) {
                onCloseRef.current();

                return;
            }

            // Snap: if dragged up past 85vh, snap to full. Otherwise snap to 72vh.
            const fullH = window.innerHeight;
            const snapFull = fullH * 0.95;
            const snapDefault = fullH * 0.72;

            let target = snapDefault;

            if (currentH > fullH * 0.8) {
                target = snapFull;
            }

            sheet!.style.transition = 'height .3s cubic-bezier(.32,1,.4,1)';
            sheet!.style.height = target + 'px';
        }

        handle.addEventListener('mousedown', onStart);
        handle.addEventListener('touchstart', onStart, { passive: false });
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);

        return () => {
            handle.removeEventListener('mousedown', onStart);
            handle.removeEventListener('touchstart', onStart);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchend', onEnd);
        };
    }, []);

    // Lock body scroll when open
    useEffect(() => {
        if (open) {
            document.body.classList.add('overflow-hidden');
        } else {
            document.body.classList.remove('overflow-hidden');
        }

        return () => document.body.classList.remove('overflow-hidden');
    }, [open]);

    // Open/close animation via height
    useEffect(() => {
        const sheet = sheetRef.current;

        if (!sheet) {
return;
}

        if (open) {
            // Reset to default height and slide up
            sheet.style.transition =
                'transform .35s cubic-bezier(.32,1,.4,1), height .35s cubic-bezier(.32,1,.4,1)';
            sheet.style.height = '72vh';
            sheet.style.transform = 'translateX(-50%) translateY(0)';
        } else {
            sheet.style.transition = 'transform .35s cubic-bezier(.32,1,.4,1)';
            sheet.style.transform = 'translateX(-50%) translateY(100%)';
        }
    }, [open]);

    return (
        <>
            {/* Overlay */}
            <div
                className={`fixed inset-0 z-[290] bg-black/30 transition-opacity duration-300 ${open ? 'opacity-100' : 'pointer-events-none opacity-0'}`}
                onClick={onClose}
            />

            {/* Sheet — 600px desktop, 100% mobile */}
            <div
                ref={sheetRef}
                className="fixed bottom-0 left-1/2 z-[300] flex w-full flex-col overflow-hidden rounded-t-[20px] bg-white shadow-[0_-8px_40px_rgba(0,0,0,0.16)] will-change-transform md:w-[600px] dark:bg-[#1E1D15]"
                style={{
                    height: '72vh',
                    transform: 'translateX(-50%) translateY(100%)',
                }}
            >
                {/* Drag handle */}
                <div
                    ref={handleRef}
                    className="flex shrink-0 cursor-grab justify-center py-3 pb-1 select-none"
                    style={{ touchAction: 'none' }}
                >
                    <div className="h-1 w-9 rounded-full bg-[#E2DFD6] transition-all hover:w-12 hover:bg-[#AAA89F]" />
                </div>

                {/* Scrollable content — pb-24 ensures content clears the dock */}
                <div
                    className="flex-1 overflow-y-auto px-5 pt-1 pb-24"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {children}
                </div>
            </div>
        </>
    );
}
