/**
 * Registers the "Shared via {brand}" view as a child of the native Shares
 * section in the Files navigation (id `shareoverview`), listing every file the
 * current user actively shares externally via Atrium — one row per file, like
 * native "Shared with others" (several shares of the same file merge into one
 * row, see entryToNode). It uses only the exported @nextcloud/files Navigation
 * API — the same one the native share views use — and a dedicated OCS overview
 * endpoint; the native "Shared with others" list is never touched (see
 * docs/internals.md "Shares overview navigation view").
 *
 * Rendering is done entirely by the native FileList (columns: []); no UI is
 * rebuilt. Each row is a real File/Folder node, so native file actions apply and
 * clicking a row opens the existing Atrium sidebar section with its recipients.
 */
import type { ContentsWithRoot, IFileAction } from '@nextcloud/files'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { DefaultType, File, FileType, Folder, getNavigation, Permission, registerFileAction, View } from '@nextcloud/files'
import { getRemoteURL, getRootPath } from '@nextcloud/files/dav'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { ShareType } from '@nextcloud/sharing'

import { APP_ID } from './app'
import type { AtriumOverviewEntry, SharePolicy } from './types'

/** Id of the native Shares section; children set this as their `parent`. */
const SHARES_SECTION_ID = 'shareoverview'
const ATRIUM_SHARES_VIEW_ID = 'atrium-shares'
/**
 * Order 7: last in the section (after the native pendingshares=6), non-invasive.
 * Native orders: sharingin=1, sharingout=2, sharinglinks=3, filerequest=4,
 * deletedshares=5, pendingshares=6.
 */
const ATRIUM_SHARES_VIEW_ORDER = 7

/**
 * The view icon, inlined as a string (the Navigation API takes a raw SVG). We
 * inline it rather than importing the .svg file so no svg-loader is needed; it is
 * the same shield mark as img/app.svg and inherits the theme colour.
 */
const ATRIUM_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M8 1 2 3.2v3.3c0 3.4 2.3 6.6 6 8 3.7-1.4 6-4.6 6-8V3.2L8 1zm0 1.6 4.5 1.6v2.3c0 2.6-1.7 5.1-4.5 6.3-2.8-1.2-4.5-3.7-4.5-6.3V4.2L8 2.6zM8 5a1.6 1.6 0 0 0-.6 3.1v1.5a.6.6 0 0 0 1.2 0V8.1A1.6 1.6 0 0 0 8 5z"/></svg>'

/** The brand-name fallback, matching the backend policy default. */
const DEFAULT_BRAND = 'Atrium'

/**
 * fetchBrandName reads the configurable brand name from the public policy route
 * (the same the sidebar reads). Any failure falls back to the default so the
 * view still registers with a sensible label.
 */
async function fetchBrandName(): Promise<string> {
	try {
		const { data } = await axios.get(generateOcsUrl('/apps/atrium_secureshare/api/v1/policy'))
		const policy = data.ocs.data as SharePolicy
		return policy.whitelabelName || DEFAULT_BRAND
	} catch {
		return DEFAULT_BRAND
	}
}

/**
 * entryToNode turns one group of overview entries for the SAME file into a
 * real File/Folder node, mirroring the native SharingService.ocsEntryToNode.
 *
 * `source` must be byte-identical to the real DAV URL — no per-share
 * disambiguator. `Node.basename()`/`dirname()` (`@nextcloud/paths`) are naive
 * string slices on `source`, NOT URL-aware (unlike the URL-parsed `path`
 * getter or the `displayname` override, which stay clean); the legacy
 * files_sharing bridge `FileInfo.ts` feeds them straight into the native
 * sidebar's "people with access" lookup, so any modified source corrupts that
 * into a nonexistent path. The Files store (keyed solely by `source`) can
 * therefore only hold ONE node per file — matching how native
 * SharingService.ts itself `groupBy('source')`-merges multiple shares of one
 * file into a single row. `getContents` groups entries by fileId before
 * calling this; the group's first (newest) entry is the row's representative
 * for every field except `share-types`, which gets one `ShareType.Email` per
 * share in the group — the same aggregation native code performs, and what
 * makes the native `sharingStatusAction` show "Shared multiple times with
 * different people" once a file has more than one active share. Clicking the
 * row (or its row-actions button) opens the existing Atrium sidebar section,
 * which lists every recipient for the file regardless of which share "wins"
 * the row.
 *
 * The node `id` is the real file id, so native file actions apply. Permission
 * is READ-only on purpose: view/download/details only, no rename/move/delete
 * of the underlying file from this overview. The server enforces the real
 * permissions on any actual operation regardless.
 *
 * `share-types`/`sharees` mirror the native ocsEntryToNode shape (an Atrium
 * share is reported as ShareType.Email everywhere, same as AtriumShareProvider's
 * indicator in the main Files list) — this is what the native, global
 * `sharingStatusAction` FileAction (registered once by files_sharing) reads to
 * render the "Shared" icon/label inline in the row-actions cell and open the
 * sidebar's Sharing tab on click; no custom column or component needed.
 */
function entryToNode(group: AtriumOverviewEntry[], remoteBase: string, owner: string | null): File | Folder {
	const entry = group[0]!
	const NodeClass = entry.isFolder ? Folder : File
	const cleanPath = entry.path.replace(/^\/+/, '')
	const source = `${remoteBase}/${cleanPath}`
	return new NodeClass({
		id: entry.fileId,
		source,
		owner,
		mime: entry.isFolder ? undefined : (entry.mimetype || 'application/octet-stream'),
		mtime: entry.mtime ? new Date(entry.mtime * 1000) : undefined,
		size: entry.size,
		permissions: Permission.READ,
		root: getRootPath(),
		displayname: entry.name,
		attributes: {
			atriumShareId: entry.id,
			recipientEmail: entry.recipientEmail,
			'share-types': group.map(() => ShareType.Email),
			sharees: {
				sharee: {
					id: entry.recipientEmail,
					'display-name': entry.recipientEmail,
					type: ShareType.Email,
				},
			},
		},
	})
}

/**
 * groupByFileId groups overview entries by fileId — several active shares of
 * the same file become one row (see the node-identity constraint on
 * entryToNode). Entries arrive newest-first from the backend; that order is
 * preserved both across groups and within each group.
 */
function groupByFileId(entries: AtriumOverviewEntry[]): AtriumOverviewEntry[][] {
	const groups = new Map<number, AtriumOverviewEntry[]>()
	for (const entry of entries) {
		const group = groups.get(entry.fileId)
		if (group) {
			group.push(entry)
		} else {
			groups.set(entry.fileId, [entry])
		}
	}
	return [...groups.values()]
}

/**
 * getContents loads the overview and returns the current user's files root
 * plus one node per shared file (several active shares of the same file merge
 * into one row). The view is flat, so the path is ignored.
 */
async function getContents(path: string, options?: { signal?: AbortSignal }): Promise<ContentsWithRoot> {
	const { data } = await axios.get(
		generateOcsUrl('/apps/atrium_secureshare/api/v1/overview'),
		{ signal: options?.signal },
	)
	const entries = (data.ocs?.data ?? []) as AtriumOverviewEntry[]
	const remoteBase = `${getRemoteURL()}${getRootPath()}`
	const owner = getCurrentUser()?.uid ?? null
	const contents = groupByFileId(entries).map((group) => entryToNode(group, remoteBase, owner))
	return {
		folder: new Folder({
			id: 0,
			source: remoteBase,
			owner,
			root: getRootPath(),
		}),
		contents,
	}
}

/**
 * openFolderAction makes clicking a shared FOLDER row navigate into it, like
 * native Nextcloud. Files already open their preview via a generic, view-agnostic
 * default action, so this only needs to cover folders.
 *
 * Nextcloud's own equivalent, `files_sharing:open-in-files`
 * (`apps/files_sharing/src/files_actions/openInFilesAction.ts`), does exactly
 * this — but its `enabled()` checks a hardcoded allowlist of the native share
 * view ids (`sharesViewId`, `sharedWithYouViewId`, ...), which does not include
 * ours, so it never fires for our rows and a folder click silently did
 * nothing. It cannot be extended from here (it lives in files_sharing, not
 * ours), so we register our own action with the same mechanism, scoped to our
 * view: `OCP.Files.Router.goToRoute` switches to the main 'files' view with
 * `dir` set to the folder's real path — our flat overview has no "descend
 * within this list" semantics of its own (`getContents` ignores `path`), so,
 * like native, "entering" a folder here means leaving to browse it for real.
 * `default: DefaultType.HIDDEN` makes it the row's click handler without also
 * cluttering the actions menu with a redundant visible entry.
 */
const openFolderAction: IFileAction = {
	id: `${APP_ID}:open-in-files`,
	displayName: () => t(APP_ID, 'Open in Files'),
	iconSvgInline: () => '',
	enabled: ({ nodes, view }) => view.id === ATRIUM_SHARES_VIEW_ID
		&& nodes.length === 1 && nodes[0]!.type === FileType.Folder,
	async exec({ nodes }) {
		const node = nodes[0]!
		window.OCP.Files.Router.goToRoute(null, { view: 'files', fileid: String(node.fileid) }, { dir: node.path })
		return null
	},
	order: -1000,
	default: DefaultType.HIDDEN,
}

/**
 * registerAtriumSharesView registers the child view under the native Shares
 * section, plus the folder-navigation action above. It resolves the brand name
 * first (the view name is static once registered), then registers.
 * Registration is safe regardless of whether the native section has
 * registered yet — the parent link is resolved by id at render time.
 */
export async function registerAtriumSharesView(): Promise<void> {
	registerFileAction(openFolderAction)

	const brand = await fetchBrandName()
	getNavigation().register(new View({
		id: ATRIUM_SHARES_VIEW_ID,
		name: t(APP_ID, 'Shared via {name}', { name: brand }),
		caption: t(APP_ID, 'Files and folders you shared externally via {name}.', { name: brand }),

		emptyTitle: t(APP_ID, 'No external shares'),
		emptyCaption: t(APP_ID, 'Files and folders you share via {name} will show up here.', { name: brand }),

		icon: ATRIUM_ICON_SVG,
		order: ATRIUM_SHARES_VIEW_ORDER,
		parent: SHARES_SECTION_ID,

		columns: [],

		getContents,
	}))
}
