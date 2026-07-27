const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Two bundles: the sharing-sidebar integration (atrium-sharing.ts) loaded by the
// Files app, and the admin settings form (admin/main.ts) loaded by the settings
// template.
webpackConfig.entry = {
	'atrium-sharing': path.resolve(__dirname, 'src', 'atrium-sharing.ts'),
	'atrium-admin': path.resolve(__dirname, 'src', 'admin', 'main.ts'),
}

webpackConfig.output.filename = '[name].js'
webpackConfig.output.chunkFilename = '[name]-[contenthash].js'

// Allow ts-loader to handle .vue single-file components with lang="ts".
const tsRule = webpackConfig.module.rules.find(
	(r) => r.test && r.test.toString().includes('tsx'),
)
if (tsRule) {
	const tsLoaderEntry = tsRule.use.find((u) =>
		typeof u === 'string' ? u === 'ts-loader' : u.loader === 'ts-loader',
	)
	const idx = tsRule.use.indexOf(tsLoaderEntry)
	tsRule.use[idx] = {
		loader: 'ts-loader',
		options: {
			transpileOnly: true,
			appendTsSuffixTo: [/\.vue$/],
		},
	}
}

// Nextcloud libraries and several transitive deps (axios, node-stdlib-browser
// polyfill proxies) ship strict ESM (.mjs), which webpack 5 treats as
// fullySpecified and refuses to resolve extensionless imports for. Relax that
// for node_modules so the polyfill aliases and .mjs re-exports resolve.
webpackConfig.module.rules.push({
	test: /\.m?js$/,
	resolve: { fullySpecified: false },
})

module.exports = webpackConfig
