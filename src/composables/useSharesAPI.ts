/**
 * useSharesAPI is the sidebar section's data layer: it loads, creates and revokes
 * a node's external shares through the session-authenticated OCS API. It owns the
 * reactive shares/loading/error state and surfaces success/failure as toasts.
 */
import { computed, ref, watch, type Ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import type { Node as FileNode } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { ShareType } from '@nextcloud/sharing'

import { APP_ID } from '../app'
import type { AtriumShare, ShareForm } from '../types'

const BASE = '/apps/atrium_secureshare/api/v1/shares'

function ocsMessage(error: unknown, fallback: string): string {
	const message = (error as { response?: { data?: { ocs?: { meta?: { message?: string } } } } })
		?.response?.data?.ocs?.meta?.message
	return typeof message === 'string' && message !== '' ? message : fallback
}

export function useSharesAPI(node: Ref<FileNode | undefined>) {
	const shares = ref<AtriumShare[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)

	const fileId = computed(() => node.value?.fileid ?? 0)

	// syncNodeIndicator keeps Nextcloud's own file-list "Shared" indicator in
	// step with the current shares without a reload. The indicator is driven by
	// the node's `share-types` attribute (an array of ShareType ids), otherwise
	// only refreshed on a directory PROPFIND. An Atrium share surfaces as
	// ShareType.Email (AtriumShareProvider reports TYPE_EMAIL); we toggle that id
	// on the live node — preserving any other types — and let the Files app
	// re-render the row via the files:node:updated event.
	function syncNodeIndicator(): void {
		const active = node.value
		if (!active) {
			return
		}
		// Best-effort: the share itself is already persisted, so a failure to
		// refresh the indicator must never surface as a share error. Worst case
		// the indicator lags until the next directory reload.
		try {
			const raw = active.attributes['share-types']
			const current = Array.isArray(raw) ? (raw as number[]) : []
			const withoutEmail = current.filter((type) => type !== ShareType.Email)
			const next = shares.value.length > 0 ? [...withoutEmail, ShareType.Email] : withoutEmail
			active.update({ 'share-types': next })
			emit('files:node:updated', active)
		} catch (e) {
			console.error('[atrium] failed to refresh the Shared indicator', e)
		}
	}

	async function fetchShares(): Promise<void> {
		if (!fileId.value) {
			shares.value = []
			return
		}
		loading.value = true
		error.value = null
		try {
			const { data } = await axios.get(generateOcsUrl(BASE), { params: { fileId: fileId.value } })
			shares.value = data.ocs.data as AtriumShare[]
		} catch (e) {
			error.value = ocsMessage(e, t(APP_ID, 'Could not load the shares'))
		} finally {
			loading.value = false
		}
	}

	async function createShare(form: ShareForm): Promise<boolean> {
		error.value = null
		try {
			await axios.post(generateOcsUrl(BASE), {
				fileId: fileId.value,
				recipientEmail: form.email,
				permissions: form.permissions,
				expiresAt: form.expiresAt,
				maxDownloads: form.maxDownloads,
				sendEmail: form.sendEmail,
			})
			await fetchShares()
			syncNodeIndicator()
			showSuccess(t(APP_ID, 'Share created'))
			return true
		} catch (e) {
			const message = ocsMessage(e, t(APP_ID, 'Could not create the share'))
			error.value = message
			showError(message)
			return false
		}
	}

	async function updateShare(id: number, form: ShareForm): Promise<boolean> {
		error.value = null
		try {
			await axios.put(generateOcsUrl(`${BASE}/{id}`, { id }), {
				permissions: form.permissions,
				expiresAt: form.expiresAt,
				maxDownloads: form.maxDownloads,
				sendEmail: form.sendEmail,
			})
			await fetchShares()
			showSuccess(t(APP_ID, 'Share updated'))
			return true
		} catch (e) {
			const message = ocsMessage(e, t(APP_ID, 'Could not update the share'))
			error.value = message
			showError(message)
			return false
		}
	}

	async function revokeShare(share: AtriumShare): Promise<void> {
		error.value = null
		try {
			await axios.delete(generateOcsUrl(`${BASE}/{id}`, { id: share.id }))
			await fetchShares()
			syncNodeIndicator()
			showSuccess(t(APP_ID, 'Share revoked'))
		} catch (e) {
			const message = ocsMessage(e, t(APP_ID, 'Could not revoke the share'))
			error.value = message
			showError(message)
		}
	}

	async function copyLink(share: AtriumShare): Promise<void> {
		try {
			await navigator.clipboard.writeText(share.shareUrl)
			showSuccess(t(APP_ID, 'Link copied to clipboard'))
		} catch {
			showError(t(APP_ID, 'Could not copy the link'))
		}
	}

	// Load on init and whenever the selected node changes (the sidebar reuses the
	// section across file selections).
	watch(fileId, fetchShares, { immediate: true })

	return { shares, loading, error, fetchShares, createShare, updateShare, revokeShare, copyLink }
}
