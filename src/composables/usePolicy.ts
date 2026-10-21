/**
 * usePolicy loads the share policy for the node the sidebar currently shows. The
 * admin part of it is global, but what the user may do with THIS node is not:
 * the server narrows `canShare` and `allowedModes` to their Nextcloud permissions
 * on it, so the form never offers a mode the create call would refuse. Until it
 * loads (or if the fetch fails) a permissive default keeps the form usable; the
 * server stays the authority on every create.
 */
import { ref, watch, type Ref } from 'vue'
import axios from '@nextcloud/axios'
import type { Node as FileNode } from '@nextcloud/files'
import { generateOcsUrl } from '@nextcloud/router'

import type { NodeSharePolicy } from '../types'

const POLICY_URL = '/apps/atrium_secureshare/api/v1/policy'

const DEFAULT_POLICY: NodeSharePolicy = {
	emailEnabled: true,
	emailOptOutAllowed: true,
	allowedModes: [0, 1, 2, 3],
	maxShareDurationDays: null,
	whitelabelName: 'Atrium',
	canShare: true,
}

export function usePolicy(node: Ref<FileNode | undefined>) {
	const policy = ref<NodeSharePolicy>({ ...DEFAULT_POLICY })

	// The id the latest request was made for; a response for anything else belongs
	// to a node the user has already navigated away from and is dropped.
	let requested = 0

	async function load(fileId: number): Promise<void> {
		requested = fileId
		try {
			// Without a node the global policy still carries the brand name; the
			// node-scoped fields then stay at their permissive default.
			const config = fileId > 0 ? { params: { fileId } } : {}
			const { data } = await axios.get(generateOcsUrl(POLICY_URL), config)
			if (requested === fileId) {
				policy.value = data.ocs.data as NodeSharePolicy
			}
		} catch {
			if (requested === fileId) {
				policy.value = { ...DEFAULT_POLICY }
			}
		}
	}

	watch(() => node.value?.fileid ?? 0, (fileId) => {
		void load(fileId)
	}, { immediate: true })

	return { policy }
}
