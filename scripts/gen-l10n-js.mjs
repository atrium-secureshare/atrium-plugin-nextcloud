#!/usr/bin/env node
// Generate the browser l10n catalogs (l10n/<lang>.js) from their server-side
// source (l10n/<lang>.json). Nextcloud ships each translation catalog in two
// formats with identical content: the PHP backend reads l10n/<lang>.json via
// IL10N, while the browser loads l10n/<lang>.js which calls OC.L10N.register().
// The .json is the single versioned source of truth; this script derives the
// .js so the two can never drift. Runs from `npm run build`, before the l10n/
// directory is staged into the release archive.
//
// Dependency-free: uses only Node's stdlib, so it adds no devDependency. The app
// id is read from appinfo/info.xml — the register() key must match the Nextcloud
// app id, and reading it here stops a rename from silently registering
// translations under a stale id. Exits non-zero if the id or catalogs are
// missing, so a broken l10n setup fails the build instead of shipping empty.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const rootDir = join(dirname(fileURLToPath(import.meta.url)), '..')
const l10nDir = join(rootDir, 'l10n')

const info = readFileSync(join(rootDir, 'appinfo', 'info.xml'), 'utf8')
const appId = info.match(/<id>([^<]+)<\/id>/)?.[1]
if (!appId) {
	process.stderr.write('ERROR: could not read <id> from appinfo/info.xml\n')
	process.exit(1)
}

const sources = readdirSync(l10nDir).filter((f) => f.endsWith('.json'))
if (!sources.length) {
	process.stderr.write(`ERROR: no l10n/*.json catalogs found in ${l10nDir}\n`)
	process.exit(1)
}

for (const src of sources) {
	const { translations, pluralForm } = JSON.parse(
		readFileSync(join(l10nDir, src), 'utf8'),
	)
	// Reproduce the translationtool layout: the translations object indented one
	// level inside register(), the plural form as the trailing argument.
	const body =
		'    ' + JSON.stringify(translations, null, 4).replace(/\n/g, '\n    ')
	const js = `OC.L10N.register(\n    "${appId}",\n${body},\n"${pluralForm}");\n`
	const out = src.replace(/\.json$/, '.js')
	writeFileSync(join(l10nDir, out), js)
	process.stdout.write(`l10n: ${src} -> ${out}\n`)
}
