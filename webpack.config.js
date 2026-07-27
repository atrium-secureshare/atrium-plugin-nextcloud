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

module.exports = webpackConfig
