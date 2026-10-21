import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType, ReactNode } from 'react';

type PageModule = {
    default: ComponentType & {
        layout?: (page: ReactNode) => ReactNode;
    };
};

// Pages outside the app shell: sign-in and the onboarding wizard.
// resources/views/app.blade.php holds the same list, so it links the shell's
// chunk and CSS only for pages inside it.
const WITHOUT_SHELL = /^(auth\/|onboarding$)/;

/**
 * Resolves an Inertia page and gives every app page the persistent shell
 * layout, so the header and menu stay mounted between visits. Shared by the
 * browser entry and the SSR entry so both render the same tree.
 *
 * The shell is its own chunk, loaded alongside the page only for pages inside
 * it, so the sign-in pages never download the header, Composer's tray or the
 * trip bar.
 */
export async function resolvePage(name: string): Promise<PageModule> {
    const pageLoad = resolvePageComponent<PageModule>(
        `../pages/${name}.tsx`,
        import.meta.glob<PageModule>('../pages/**/*.tsx'),
    );

    if (WITHOUT_SHELL.test(name)) {
        return pageLoad;
    }

    const [page, { default: AppShellLayout }] = await Promise.all([
        pageLoad,
        import('@/layouts/app-shell-layout'),
    ]);

    page.default.layout ??= (child: ReactNode) => (
        <AppShellLayout>{child}</AppShellLayout>
    );

    return page;
}
