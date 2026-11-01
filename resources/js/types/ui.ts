import type { ReactNode } from 'react';

export type AppLayoutProps = {
    children: ReactNode;
    /** The page sets its own column instead of the shared page frame. */
    fullWidth?: boolean;
    /** @deprecated Ignored by the shell; drop it when the page is rebuilt. */
    rightPanel?: ReactNode;
};
