/**
 * Ipsum's six colors, in its order. Since core r65224 (2026-11-03) they carry Twenty Twenty-Five's slugs: Base,
 * Contrast, Accent, Strong Contrast, Surface, Border (before: theme-1, theme-2, theme-3, theme-6, theme-5, theme-4).
 * Kept free of WordPress imports so the Node tests and bin/ready-recipes.mjs can load it.
 */
export const ROLES = [
	'base',
	'contrast',
	'accent-1',
	'accent-3',
	'accent-5',
	'accent-6',
];

/**
 * Whether a palette slug is one of Ipsum's six colors.
 *
 * @param {string} slug Slug.
 * @return {boolean} Whether it is.
 */
export const isRole = ( slug ) => ROLES.includes( slug );
