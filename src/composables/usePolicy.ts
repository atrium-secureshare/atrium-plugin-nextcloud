/**
 * usePolicy loads the public share policy once for the sidebar section — it is
 * global, not per-node. Until it loads (or if the fetch fails) a permissive
 * default keeps the form usable; the server stays the authority on every create.
 */
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import type { SharePolicy } from '../types'

const DEFAULT_POLICY: SharePolicy = {
	emailEnabled: true,
	emailOptOutAllowed: true,
	allowedModes: [0, 1, 2, 3],
	maxShareDurationDays: null,
	whitelabelName: 'Atrium',
}

export function usePolicy() {
	const policy = ref<SharePolicy>({ ...DEFAULT_POLICY })

	onMounted(async () => {
		try {
			const { data } = await axios.get(generateOcsUrl('/apps/atrium_secureshare/api/v1/policy'))
			policy.value = data.ocs.data as SharePolicy
		} catch {
			// Keep the permissive default; the server enforces the real policy.
		}
	})

	return { policy }
}
