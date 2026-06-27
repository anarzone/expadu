/**
 * Expadu v4 design-system primitives — "orange acts, cyan locates".
 *
 * The Expadu-branded layer that encodes the v4 discipline (warm-paper canvas,
 * a single hot-orange primary, cyan reserved for origin/distance, literal
 * status colors, one soft shadow). Screens migrate onto these, replacing the
 * generic shadcn primitives in `@/components/ui`.
 */
export {
    Button,
    IconButton,
    buttonVariants,
    iconButtonVariants,
} from './button';
export { Pill, pillVariants } from './pill';
export { Segmented } from './segmented';
export type { SegmentedOption } from './segmented';
export { Tag, tagVariants } from './tag';
export { Tile, tileIconVariants } from './tile';
export { Field } from './field';
export { Surface } from './surface';
export { CountBadge } from './count-badge';
export { Skeleton } from './skeleton';
export { categoryClass, CATEGORY_CLASSES } from './category';
