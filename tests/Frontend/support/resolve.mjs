// Lets Node's test runner load the app's TypeScript modules the way Vite does: the `@/` alias
// for resources/js, and imports written without their `.ts` extension. Test-only.
import { existsSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = new URL('../../../resources/js/', import.meta.url);

const file = (url) => {
    try {
        return statSync(fileURLToPath(url)).isFile();
    } catch {
        return false;
    }
};

export async function resolve(specifier, context, next) {
    const local = specifier.startsWith('@/')
        ? new URL(specifier.slice(2), root).href
        : specifier.startsWith('.') && context.parentURL?.startsWith('file:')
          ? new URL(specifier, context.parentURL).href
          : null;
    if (local !== null && !file(local)) {
        for (const candidate of [`${local}.ts`, `${local}/index.ts`]) {
            if (existsSync(fileURLToPath(candidate)) && file(candidate)) {
                return next(candidate, context);
            }
        }
    }

    return next(local ?? specifier, context);
}
