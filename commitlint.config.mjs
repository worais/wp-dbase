// https://commitlint.js.org — Conventional Commits for every commit in a pull request.
export default {
	extends: [ '@commitlint/config-conventional' ],
	rules: {
		'header-max-length': [ 2, 'always', 72 ],
		'body-max-line-length': [ 1, 'always', 100 ],
	},
	// Dependabot writes "Bump x from 1 to 2" (sentence case) and long URLs in the body.
	ignores: [ ( message ) => /^Signed-off-by: dependabot\[bot\]/m.test( message ) ],
};
