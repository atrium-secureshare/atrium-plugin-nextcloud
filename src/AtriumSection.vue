<template>
	<div class="atrium-section">
		<h4 class="atrium-section__title">
			{{ t('atrium_secureshare', 'External sharing via {name}', { name: policy.whitelabelName }) }}
		</h4>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<ShareForm
			:is-folder="isFolder"
			:policy="policy"
			:shares="shares"
			:editing="editing"
			:create="createShare"
			:update="updateShare"
			:start-edit="startEdit"
			:cancel-edit="cancelEdit" />

		<SharesList
			:shares="shares"
			:loading="loading"
			:copy="copyLink"
			:edit="startEdit"
			:revoke="revokeShare" />
	</div>
</template>

<script setup lang="ts">
import { computed, ref, toRef, watch } from 'vue'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { translate as t } from '@nextcloud/l10n'
import type { Node as FileNode } from '@nextcloud/files'
import { FileType } from '@nextcloud/files'

import ShareForm from './components/ShareForm.vue'
import SharesList from './components/SharesList.vue'
import { usePolicy } from './composables/usePolicy'
import { useSharesAPI } from './composables/useSharesAPI'
import type { AtriumShare } from './types'

const props = defineProps<{ node: FileNode }>()

const isFolder = computed(() => props.node?.type === FileType.Folder)

const { policy } = usePolicy()
const { shares, loading, error, createShare, updateShare, revokeShare, copyLink } = useSharesAPI(toRef(props, 'node'))

const editing = ref<AtriumShare | null>(null)
const startEdit = (share: AtriumShare): void => {
	editing.value = share
}
const cancelEdit = (): void => {
	editing.value = null
}

// Selecting another file, or the edited share leaving the list (revoked, or
// updated below its cap), drops us back to a clean create form.
watch(() => props.node, cancelEdit)
watch(shares, (list) => {
	if (editing.value && !list.some((s) => s.id === editing.value?.id)) {
		cancelEdit()
	}
})
</script>

<style scoped>
.atrium-section {
	display: flex;
	flex-direction: column;
	padding: 8px 0;
}

.atrium-section__title {
	margin: 0 0 8px;
	font-weight: 600;
}
</style>
