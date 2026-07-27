#!/usr/bin/env node
// Collect third-party license notices for the frontend's *runtime* dependencies
// (package.json "dependencies" — devDependencies are build tooling and are not
// shipped in the bundle). Reads each installed package's own license text from
// node_modules and prints an aggregated notice to stdout. Dependency-free: uses
// only Node's stdlib, so it adds no devDependency of its own.
//
// Exits non-zero if a shipped dependency has no detectable license, so an
// unvetted dependency breaks the build instead of silently shipping.
import { readFileSync, existsSync, readdirSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const rootDir = join(dirname(fileURLToPath(import.meta.url)), '..')
const nodeModules = join(rootDir, 'node_modules')
const pkg = JSON.parse(readFileSync(join(rootDir, 'package.json'), 'utf8'))
const deps = Object.keys(pkg.dependencies ?? {}).sort()

const LICENSE_FILES = [
	'LICENSE',
	'LICENSE.md',
	'LICENSE.txt',
	'LICENCE',
	'LICENCE.md',
	'COPYING',
]

function licenseId(meta) {
	if (typeof meta.license === 'string') return meta.license
	if (meta.license && typeof meta.license === 'object' && meta.license.type)
		return meta.license.type
	if (Array.isArray(meta.licenses))
		return meta.licenses.map((l) => l.type ?? l).join(' OR ')
	return null
}

function licenseText(dir) {
	for (const f of LICENSE_FILES) {
		const p = join(dir, f)
		if (existsSync(p)) return readFileSync(p, 'utf8').trim()
	}
	for (const name of readdirSync(dir)) {
		if (/^(licen[sc]e|copying|notice)/i.test(name)) {
			try {
				return readFileSync(join(dir, name), 'utf8').trim()
			} catch {
				// Directory or unreadable entry — skip.
			}
		}
	}
	return null
}

function repoUrl(meta) {
	const r = meta.repository
	const url = typeof r === 'string' ? r : r?.url
	return (url ?? meta.homepage ?? '').replace(/^git\+/, '').replace(/\.git$/, '')
}

const sep = '='.repeat(80)
const blocks = []
const missing = []

for (const dep of deps) {
	const dir = join(nodeModules, dep)
	const metaPath = join(dir, 'package.json')
	if (!existsSync(metaPath)) {
		missing.push(`${dep} (not installed — run npm ci first)`)
		continue
	}
	const meta = JSON.parse(readFileSync(metaPath, 'utf8'))
	const id = licenseId(meta)
	const text = licenseText(dir)
	if (!id && !text) {
		missing.push(`${dep} (no license metadata or file)`)
		continue
	}
	const repo = repoUrl(meta)
	blocks.push(
		`${sep}\n${dep} ${meta.version ?? ''} — ${id ?? 'see text below'}\n` +
			`${repo ? repo + '\n' : ''}${sep}\n\n` +
			`${text ?? `(no license file bundled in package; declared license: ${id})`}\n`,
	)
}

if (missing.length) {
	process.stderr.write(
		`ERROR: shipped dependencies without a detectable license:\n  - ${missing.join('\n  - ')}\n`,
	)
	process.exit(1)
}

process.stdout.write(blocks.join('\n'))
