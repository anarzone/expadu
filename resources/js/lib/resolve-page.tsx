import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType, ReactNode } from 'react';
import AppShellLayout from '@/layouts/app-shell-layout';

type PageModule = {
    default: ComponentType & {
        layout?: (page: ReactNode) => ReactNode;
    };
};

// Pages outside the app shell: sign-in and the onboarding wizard.
const WITHOUT_SHELL = /^(auth\/|onboarding$)/;

const inShell = (page: ReactNode) => <AppShellLayout>{page}</AppShellLayout>;

/**
 * Resolves an Inertia page and gives every app page the persistent shell
 * layout, so the header and menu stay mounted between visits. Shared by the
 * browser entry and the SSR entry so both render the same tree.
 */
export async function resolvePage(name: string): Promise<PageModule> {
    const page = await resolvePageComponent<PageModule>(
        `../pages/${name}.tsx`,
        import.meta.glob<PageModule>('../pages/**/*.tsx'),
    );

    if (!WITHOUT_SHELL.test(name)) {
        page.default.layout ??= inShell;
    }

    return page;
}
