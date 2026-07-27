<!--
  SharesList renders the node's external shares with per-share copy-link, edit and
  revoke actions. Expired/exhausted shares still inside the retention grace window
  are shown too — dimmed, with a status label, sorted below the active ones — so
  the owner can see why a share stopped and reactivate it by editing. Presentational:
  copy/edit/revoke are delegated to the parent.
-->
<template>
	<div class="atrium-shares">
		<div v-if="loading" class="atrium-shares__loading">
			<NcLoadingIcon :size="24" />
		</div>

		<template v-else-if="shares.length > 0">
			<h5 class="atrium-shares__heading">
				{{ t('atrium_secureshare', 'External shares') }} · {{ shares.length }}
			</h5>
			<ul class="atrium-shares__list">
				<li
					v-for="share in sortedShares"
					:key="share.id"
					class="atrium-shares__item"
					:class="{ 'atrium-shares__item--inactive': share.status !== 'active' }">
					<NcAvatar :display-name="share.recipientEmail" :disable-menu="true" :size="32" />
					<div class="atrium-shares__body">
						<span class="atrium-shares__email">{{ share.recipientEmail }}</span>
						<span class="atrium-shares__meta">
							<ClockAlertOutlineIcon v-if="share.status === 'expired'" :size="14" />
							<CloseCircleOutlineIcon v-else-if="share.status === 'exhausted'" :size="14" />
							{{ metaLine(share) }}
						</span>
					</div>
					<NcActions>
						<NcActionButton :close-after-click="true" @click="edit(share)">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('atrium_secureshare', 'Edit') }}
						</NcActionButton>
						<NcActionButton :close-after-click="true" @click="copy(share)">
							<template #icon>
								<ContentCopyIcon :size="20" />
							</template>
							{{ t('atrium_secureshare', 'Copy link') }}
						</NcActionButton>
						<NcActionButton :close-after-click="true" @click="revoke(share)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('atrium_secureshare', 'Revoke') }}
						</NcActionButton>
					</NcActions>
				</li>
			</ul>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import ClockAlertOutlineIcon from 'vue-material-design-icons/ClockAlertOutline.vue'
import CloseCircleOutlineIcon from 'vue-material-design-icons/CloseCircleOutline.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { translate as t } from '@nextcloud/l10n'

import { formatMode } from '../permissions'
import type { AtriumShare } from '../types'

const props = defineProps<{
	shares: AtriumShare[]
	loading: boolean
	copy: (share: AtriumShare) => void
	edit: (share: AtriumShare) => void
	revoke: (share: AtriumShare) => void
}>()

/**
 * sortedShares keeps active shares on top; expired/exhausted grace-window
 * entries fall below, preserving the server order (newest first) within each
 * group. A stable sort keyed on "is active" gives exactly that.
 */
const sortedShares = computed(() =>
	[...props.shares].sort((a, b) => Number(a.status !== 'active') - Number(b.status !== 'active')),
)

function metaLine(share: AtriumShare): string {
	const mode = formatMode(share.permissions)
	if (share.status === 'expired') {
		return `${mode} · ${t('atrium_secureshare', 'Expired')}`
	}
	if (share.status === 'exhausted') {
		return `${mode} · ${t('atrium_secureshare', 'Download limit reached')}`
	}
	const parts = [mode]
	if (!share.isFolder) {
		// A null cap is unlimited: show the count over the infinity sign (∞) rather
		// than a bare number, so "no limit" reads unambiguously next to capped shares.
		const downloads = share.maxDownloads !== null
			? `${share.downloadCount} / ${share.maxDownloads}`
			: `${share.downloadCount} / ∞`
		parts.push(t('atrium_secureshare', '↓ {downloads} downloads', { downloads }))
	}
	if (share.expiresAt) {
		const date = new Date(share.expiresAt).toLocaleDateString()
		parts.push(t('atrium_secureshare', 'until {date}', { date }))
	}
	return parts.join(' · ')
}
</script>

<style scoped>
.atrium-shares__loading {
	display: flex;
	justify-content: center;
	padding: 16px 0;
}

.atrium-shares__heading {
	margin: 8px 0;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.atrium-shares__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.atrium-shares__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

/* Expired/exhausted shares are listed for the grace window but visibly dimmed. */
.atrium-shares__item--inactive {
	opacity: 0.6;
}

.atrium-shares__body {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1 1 auto;
}

.atrium-shares__email {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.atrium-shares__meta {
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
</style>
