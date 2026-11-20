/**
 * The scripts' lint: WordPress's own rules, as `wp-scripts lint-js` gives
 * them, and three things this package needs.
 */
const wordpress = require( '@wordpress/scripts/config/eslint.config.cjs' );
const globals = require( 'globals' );

module.exports = [
	...wordpress,

	// The tests run in jsdom, under Vitest.
	{
		files: [ 'tests/js/**/*.js' ],
		languageOptions: { globals: { ...globals.browser, ...globals.node } },
	},

	// A callback is a Function and a value from a page can be anything;
	// naming either more narrowly would be a guess.
	{
		rules: {
			'jsdoc/reject-function-type': 'off',
			'jsdoc/reject-any-type': 'off',
		},
	},
];
