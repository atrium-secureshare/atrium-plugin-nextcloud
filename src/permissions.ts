/**
 * Atrium sharing modes. A share stores one integer `permissions` value; these
 * are its meanings. A single file can only ever be read-only (mode 0); folders
 * additionally support upload modes. The upload-mode semantics are enforced
 * server-side — here they only drive the picker labels.
 */
import { translate as t } from '@nextcloud/l10n'

import { APP_ID } from './app'

export const ShareMode = {
	ReadOnly: 0,
	UploadDownloadOwn: 1,
	UploadDownloadAll: 2,
	UploadOnly: 3,
} as const

export interface ShareModeOption {
	value: number
	label: string
}

export function fileModes(): ShareModeOption[] {
	return [{ value: ShareMode.ReadOnly, label: t(APP_ID, 'Read-only') }]
}

export function folderModes(): ShareModeOption[] {
	return [
		{ value: ShareMode.ReadOnly, label: t(APP_ID, 'Read-only') },
		{ value: ShareMode.UploadDownloadOwn, label: t(APP_ID, 'Upload and download (own files)') },
		{ value: ShareMode.UploadDownloadAll, label: t(APP_ID, 'Upload and download (all files)') },
		{ value: ShareMode.UploadOnly, label: t(APP_ID, 'Upload only (drop folder)') },
	]
}

export function formatMode(permissions: number): string {
	const all = [...folderModes()]
	return all.find((m) => m.value === permissions)?.label ?? t(APP_ID, 'Read-only')
}
