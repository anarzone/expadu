import AppLogoIcon from '@/components/app-logo-icon';

export function MobileTopBar({ title }: { title?: string }) {
    return (
        <header className="fixed top-0 right-0 left-0 z-50 flex items-center justify-between border-b border-border bg-background/96 px-5 py-[11px] backdrop-blur-2xl md:hidden">
            <div className="flex items-center gap-2.5">
                <AppLogoIcon className="!size-7 !rounded-lg !text-sm" />
                <span className="font-display text-[21px] font-medium tracking-tight">
                    <span className="text-primary">E</span>xpadu
                </span>
            </div>
            {title && (
                <span className="text-sm font-semibold text-primary">{title}</span>
            )}
        </header>
    );
}
