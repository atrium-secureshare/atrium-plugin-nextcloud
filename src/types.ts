/**
 * Shared types for the Atrium sharing sidebar section. The share shape mirrors
 * the camelCase DTO the SidebarShareController returns; the form shape is what
 * the create endpoint accepts.
 */

/**
 * ShareStatus is the retention state of a share as the sidebar sees it: an
 * active share, or an expired/exhausted one still inside the grace window.
 */
export type ShareStatus = 'active' | 'expired' | 'exhausted'

/**
 * AtriumShare is one external share as returned by the sidebar API. It is active
 * unless `status` says otherwise — expired/exhausted shares are listed for the
 * retention grace window so the owner can see and reactivate them.
 */
export interface AtriumShare {
	/** Internal numeric id — the identifier the revoke endpoint takes. */
	id: number
	recipientEmail: string
	/** Sharing mode (see permissions.ts): 0 read-only, 1..3 folder modes. */
	permissions: number
	maxDownloads: number | null
	downloadCount: number
	/** RFC3339 instant, or null when the share never expires. */
	expiresAt: string | null
	createdAt: string | null
	emailSent: boolean
	/** Retention state: active, or expired/exhausted within the grace window. */
	status: ShareStatus
	isFolder: boolean
	fileName: string
	/** Atrium portal the recipient signs in to; the link the owner shares. */
	shareUrl: string
}

/**
 * AtriumOverviewEntry is one active share returned by the overview endpoint that
 * backs the "Shared via {brand}" navigation view. It carries the share fields
 * plus the resolved node fields the frontend needs to build a real File/Folder
 * node. One entry per share (no dedup): the same file shared to several
 * recipients yields several entries, distinguished by `id`.
 */
export interface AtriumOverviewEntry {
	/** Internal numeric share id — makes the node source unique per share. */
	id: number
	recipientEmail: string
	/** Sharing mode (0 read-only, 1..3 folder modes) — NOT an NC bitmask. */
	permissions: number
	maxDownloads: number | null
	downloadCount: number
	expiresAt: string | null
	createdAt: string | null
	emailSent: boolean
	/** The shared node's file id (the node identity for actions/sidebar). */
	fileId: number
	/** Path relative to the owner's files root, e.g. `folder/report.pdf`. */
	path: string
	/** The node's display name. */
	name: string
	isFolder: boolean
	/** Modification time in unix seconds. */
	mtime: number
	size: number
	mimetype: string
}

/**
 * SharePolicy is the public admin policy the sidebar reads to shape the create
 * form. It carries no trust key — see AdminConfig for the admin-only full view.
 */
export interface SharePolicy {
	/** Whether invitation emails are sent at all (global master switch). */
	emailEnabled: boolean
	/** Whether the owner may opt out of notifying the recipient per share. */
	emailOptOutAllowed: boolean
	/** Sharing modes the policy permits (subset of 0..3). */
	allowedModes: number[]
	/** Maximum share duration in days, or null when unlimited. */
	maxShareDurationDays: number | null
	/** Interim brand name shown in the sidebar heading. */
	whitelabelName: string
}

/** AdminConfig is the full admin configuration (SharePolicy plus trust config). */
export interface AdminConfig extends SharePolicy {
	/** Core signing key in PEM form; '' when trust is not configured. */
	corePublicKey: string
	/** SHA256 fingerprint of the installed key, or null when none is set. */
	keyFingerprint: string | null
	/** Configured portal base URL; '' falls back to this instance's base URL. */
	portalUrl: string
	/**
	 * Days an expired/exhausted share stays visible to its owner before the
	 * cleanup job removes it; 0 means no grace. Admin-only, not part of the public
	 * policy the sidebar reads.
	 */
	retentionDays: number
}

/** ShareForm is the create payload the sidebar collects from the owner. */
export interface ShareForm {
	email: string
	permissions: number
	/** RFC3339 instant, or null for no expiry. */
	expiresAt: string | null
	/** Files only; null means unlimited. */
	maxDownloads: number | null
	sendEmail: boolean
}
