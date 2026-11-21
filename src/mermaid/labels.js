/**
 * Style Mermaid: words shown to people (translatable), by id.
 */
import { __ } from '@wordpress/i18n';

// Ipsum's names for its six colors, in the order of ROLES (roles.js).
export const roleNames = () => [
	__( 'Base', 'windrose-livery' ),
	__( 'Contrast', 'windrose-livery' ),
	__( 'Accent', 'windrose-livery' ),
	__( 'Strong Contrast', 'windrose-livery' ),
	__( 'Surface', 'windrose-livery' ),
	__( 'Border', 'windrose-livery' ),
];

export const roleHelp = () => [
	__( 'Page background', 'windrose-livery' ),
	__( 'Body text, links, buttons', 'windrose-livery' ),
	__( 'Highlights, free for content', 'windrose-livery' ),
	__( 'Hover, strong ink', 'windrose-livery' ),
	__( 'Cards, quotes, code', 'windrose-livery' ),
	__( 'Lines and borders', 'windrose-livery' ),
];

export const moodNames = () => ( {
	calm: __( 'Calm', 'windrose-livery' ),
	warm: __( 'Warm', 'windrose-livery' ),
	bold: __( 'Bold', 'windrose-livery' ),
	dark: __( 'Dark', 'windrose-livery' ),
	contrast: __( 'High contrast', 'windrose-livery' ),
} );

export const checkNames = () => ( {
	text: __( 'Text on Base', 'windrose-livery' ),
	'text-soft': __( 'Text on Surface', 'windrose-livery' ),
	link: __( 'Links', 'windrose-livery' ),
	'link-hover': __( 'Links on hover', 'windrose-livery' ),
	heading: __( 'Headings (large text)', 'windrose-livery' ),
	button: __( 'Button text', 'windrose-livery' ),
	'button-hover': __( 'Button text on hover', 'windrose-livery' ),
	caption: __( 'Captions', 'windrose-livery' ),
	accent: __( 'Accent as text on Base', 'windrose-livery' ),
	'accent-surface': __( 'Base on Accent', 'windrose-livery' ),
	strong: __( 'Base on Strong Contrast', 'windrose-livery' ),
} );

export const scriptNames = () => ( {
	'cyrillic-greek': __( 'Cyrillic and Greek', 'windrose-livery' ),
	arabic: __( 'Arabic (also Persian, Urdu)', 'windrose-livery' ),
	hebrew: __( 'Hebrew', 'windrose-livery' ),
	devanagari: __( 'Devanagari (Hindi, Marathi, Nepali)', 'windrose-livery' ),
	thai: __( 'Thai', 'windrose-livery' ),
	bengali: __( 'Bengali', 'windrose-livery' ),
	tamil: __( 'Tamil', 'windrose-livery' ),
	gujarati: __( 'Gujarati', 'windrose-livery' ),
	gurmukhi: __( 'Gurmukhi (Punjabi)', 'windrose-livery' ),
	georgian: __( 'Georgian', 'windrose-livery' ),
	armenian: __( 'Armenian', 'windrose-livery' ),
	ethiopic: __( 'Ethiopic (Amharic, Tigrinya)', 'windrose-livery' ),
	'chinese-simplified': __( 'Chinese (Simplified)', 'windrose-livery' ),
	'chinese-traditional': __( 'Chinese (Traditional)', 'windrose-livery' ),
	japanese: __( 'Japanese', 'windrose-livery' ),
	korean: __( 'Korean', 'windrose-livery' ),
} );

export const languageNames = () => ( {
	fa: __( 'Persian', 'windrose-livery' ),
	ur: __( 'Urdu', 'windrose-livery' ),
} );

export const categoryNames = () => ( {
	sans: __( 'Sans serif', 'windrose-livery' ),
	serif: __( 'Serif', 'windrose-livery' ),
	display: __( 'Display', 'windrose-livery' ),
	mono: __( 'Monospace', 'windrose-livery' ),
	remote: __( 'From Google Fonts or Bunny Fonts', 'windrose-livery' ),
	library: __( 'In the Font Library', 'windrose-livery' ),
} );

export const stepNames = () => [
	{ id: 'start', label: __( 'Start', 'windrose-livery' ) },
	{ id: 'fonts', label: __( 'Fonts', 'windrose-livery' ) },
	{ id: 'colors', label: __( 'Colors', 'windrose-livery' ) },
	{ id: 'typography', label: __( 'Typography', 'windrose-livery' ) },
	{ id: 'layout', label: __( 'Layout', 'windrose-livery' ) },
	{ id: 'blocks', label: __( 'Blocks', 'windrose-livery' ) },
	{ id: 'finish', label: __( 'Finish', 'windrose-livery' ) },
];
