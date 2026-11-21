/**
 * wp-scripts' own configuration (built with `--experimental-modules`), plus one script module: the light and dark
 * switch's view, `src/light-dark-switch/view.js` into `build/light-dark-switch-view.js` (its block.json lives in
 * blocks/, outside src/, so wp-scripts finds no module entry by itself). The scripts keep the entries listed in
 * package.json.
 */
const [
	scripts,
	modules,
] = require( '@wordpress/scripts/config/webpack.config' );

module.exports = [
	scripts,
	{
		...modules,
		entry: {
			'light-dark-switch-view': './src/light-dark-switch/view.js',
		},
	},
];
