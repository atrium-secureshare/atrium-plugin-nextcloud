import { reactive, ref } from 'vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import { APP_ID } from '../app'
import type { AdminConfig } from '../types'

const SETTINGS_URL = '/apps/atrium_secureshare/admin/settings'

function errorMessage(error: unknown, fallback: string): string {
	const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
	return typeof message === 'string' && message !== '' ? message : fallback
}

export function useAdminSettings() {
	const config = reactive<AdminConfig>(loadState<AdminConfig>(APP_ID, 'adminConfig'))
	const saving = ref(false)

	async function save(): Promise<void> {
		saving.value = true
		try {
			const { data } = await axios.put<AdminConfig>(generateUrl(SETTINGS_URL), {
				corePublicKey: config.corePublicKey,
				portalUrl: config.portalUrl,
				emailEnabled: config.emailEnabled,
				emailOptOutAllowed: config.emailOptOutAllowed,
				allowedModes: config.allowedModes,
				maxShareDurationDays: config.maxShareDurationDays ?? 0,
				retentionDays: config.retentionDays ?? 0,
				whitelabelName: config.whitelabelName,
			})
			// Adopt the server's canonical view (e.g. the recomputed fingerprint).
			Object.assign(config, data)
			showSuccess(t(APP_ID, 'Settings saved'))
		} catch (e) {
			showError(errorMessage(e, t(APP_ID, 'Could not save the settings')))
		} finally {
			saving.value = false
		}
	}

	return { config, saving, save }
}
