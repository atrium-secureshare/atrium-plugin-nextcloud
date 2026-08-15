<!--
  ShareForm collects one external share (recipient, mode, expiry, download cap).
  The admin policy shapes the form; the server re-enforces all of it, so the form
  only guides. The same form doubles as the editor: picking a recipient that
  already has a share of this node switches into edit mode — the email becomes
  read-only (it is the share's identity) and submit updates instead of creating.
-->
<template>
	<form class="atrium-share-form" @submit.prevent="onSubmit">
		<NcNoteCard v-if="!canShare" type="warning">
			{{ t('atrium_secureshare', 'Sharing is disabled by the administrator for this item.') }}
		</NcNoteCard>

		<NcNoteCard v-if="isEditing" type="info">
			{{ t('atrium_secureshare', 'Editing the share with {email}.', { email }) }}
		</NcNoteCard>

		<NcTextField
			v-model="email"
			type="email"
			:label="t('atrium_secureshare', 'Recipient email')"
			:error="emailTouched && !isEmailValid"
			:helper-text="emailTouched && !isEmailValid ? t('atrium_secureshare', 'Enter a valid email address') : ''"
			:disabled="isEditing"
			:list="datalistId"
			autocomplete="email"
			@blur="emailTouched = true" />
		<datalist :id="datalistId">
			<option v-for="known in knownEmails" :key="known" :value="known" />
		</datalist>

		<div class="atrium-share-form__field">
			<label class="atrium-share-form__label">{{ t('atrium_secureshare', 'Permission') }}</label>
			<NcSelect
				v-if="isFolder"
				v-model="permissions"
				:options="modeOptions"
				:reduce="(o) => o.value"
				label="label"
				:clearable="false"
				:input-label="t('atrium_secureshare', 'Permission')" />
			<p v-else class="atrium-share-form__static">
				{{ t('atrium_secureshare', 'Read-only') }}
			</p>
		</div>

		<div class="atrium-share-form__field">
			<label class="atrium-share-form__label">
				{{ expiryRequired ? t('atrium_secureshare', 'Expires on (required)') : t('atrium_secureshare', 'Expires on') }}
			</label>
			<NcDateTimePicker
				v-model="expiresAt"
				type="date"
				:min="minExpiry"
				:max="maxExpiry"
				:placeholder="expiryRequired ? t('atrium_secureshare', 'Select a date') : t('atrium_secureshare', 'No expiry')" />
			<p v-if="expiryRequired" class="atrium-share-form__hint">
				{{ t('atrium_secureshare', 'Shares may last at most {days} days.', { days: policy.maxShareDurationDays }) }}
			</p>
		</div>

		<NcTextField
			v-if="!isFolder"
			v-model="maxDownloads"
			type="number"
			min="1"
			:label="t('atrium_secureshare', 'Maximum downloads')"
			:helper-text="t('atrium_secureshare', 'Leave empty for unlimited downloads.')" />

		<NcCheckboxRadioSwitch v-if="showEmailToggle" v-model="sendEmail">
			{{ t('atrium_secureshare', 'Notify the recipient by email') }}
		</NcCheckboxRadioSwitch>

		<div class="atrium-share-form__actions">
			<NcButton
				type="primary"
				native-type="submit"
				:disabled="!isFormValid || submitting">
				<template #icon>
					<NcLoadingIcon v-if="submitting" :size="20" />
					<CheckIcon v-else-if="isEditing" :size="20" />
					<PlusIcon v-else :size="20" />
				</template>
				{{ isEditing ? t('atrium_secureshare', 'Update share') : t('atrium_secureshare', 'Create share') }}
			</NcButton>
			<NcButton
				v-if="isEditing"
				type="tertiary"
				:disabled="submitting"
				@click="cancelEdit">
				{{ t('atrium_secureshare', 'Cancel') }}
			</NcButton>
		</div>
	</form>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { translate as t } from '@nextcloud/l10n'

import { fileModes, folderModes, ShareMode } from '../permissions'
import type { AtriumShare, SharePolicy, ShareForm } from '../types'

const props = defineProps<{
	isFolder: boolean
	policy: SharePolicy
	// The node's existing active shares — the source of the email autocomplete and
	// the edit-mode detection (a recipient already present is edited, not doubled).
	shares: AtriumShare[]
	// The share currently being edited, or null when creating. Controlled by the
	// parent so the per-row edit button and this form share one edit state.
	editing: AtriumShare | null
	create: (form: ShareForm) => Promise<boolean>
	update: (id: number, form: ShareForm) => Promise<boolean>
	startEdit: (share: AtriumShare) => void
	cancelEdit: () => void
}>()

const email = ref('')
const emailTouched = ref(false)
const permissions = ref<number>(ShareMode.ReadOnly)
const expiresAt = ref<Date | null>(null)
const maxDownloads = ref<string>('')
const sendEmail = ref(true)
const submitting = ref(false)

// A stable id so the <datalist> and the input's `list` attribute match; scoped by
// the app namespace to avoid colliding with other datalists on the page.
const datalistId = 'atrium-share-recipients'

const isEditing = computed(() => props.editing !== null)

const knownEmails = computed(() => props.shares.map((s) => s.recipientEmail))

// The picker is day-granular and submitted dates run to the end of their day, so
// today is a valid choice: the share then lasts until midnight.
function startOfToday(): Date {
	const d = new Date()
	d.setHours(0, 0, 0, 0)
	return d
}

const modeOptions = computed(() => {
	const base = props.isFolder ? folderModes() : fileModes()
	return base.filter((m) => props.policy.allowedModes.includes(m.value))
})

// A file is always mode 0; sharing it is possible only if the policy allows mode 0.
// A folder is shareable if at least one of its modes is allowed.
const canShare = computed(() => modeOptions.value.length > 0)

const expiryRequired = computed(() => (props.policy.maxShareDurationDays ?? 0) > 0)

// Only offer the notify toggle when the owner actually has a choice: emails must
// be enabled globally AND opting out must be permitted. Otherwise the recipient
// is either never or always notified, and there is nothing to toggle.
const showEmailToggle = computed(() => props.policy.emailEnabled && props.policy.emailOptOutAllowed)

// v9's NcDateTimePicker bounds the selectable range via min/max Date props (the
// old disabled-date predicate is gone). min is today; max is the policy ceiling
// (now + maxShareDurationDays), or unset when shares may last indefinitely.
const minExpiry = computed(() => startOfToday())
const maxExpiry = computed<Date | undefined>(() => {
	const maxDays = props.policy.maxShareDurationDays ?? 0
	if (maxDays <= 0) {
		return undefined
	}
	const max = new Date()
	max.setHours(23, 59, 59, 999)
	max.setDate(max.getDate() + maxDays)
	return max
})

const isEmailValid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim()))

const isFormValid = computed(() =>
	canShare.value && isEmailValid.value && (!expiryRequired.value || expiresAt.value !== null),
)

// Keep the selected mode within the allowed set: default to the first allowed
// mode and correct it whenever the policy or node type narrows the options.
watch(modeOptions, (options) => {
	if (options.length > 0 && !options.some((o) => o.value === permissions.value)) {
		permissions.value = options[0].value
	}
}, { immediate: true })

// When editing starts, pre-fill from the share; when it ends, clear back to a
// blank create form. This is the single place field state follows the edit state.
watch(() => props.editing, (share) => {
	if (share) {
		fillFrom(share)
	} else {
		reset()
	}
})

// Typing (or picking from the datalist) a recipient that already shares this node
// switches into editing that share, so the same address can never be doubled.
watch(email, (value) => {
	if (props.editing !== null) {
		return
	}
	const match = findShareByEmail(value)
	if (match) {
		props.startEdit(match)
	}
})

function findShareByEmail(value: string): AtriumShare | undefined {
	const needle = value.trim().toLowerCase()
	if (needle === '') {
		return undefined
	}
	return props.shares.find((s) => s.recipientEmail.toLowerCase() === needle)
}

function fillFrom(share: AtriumShare): void {
	email.value = share.recipientEmail
	emailTouched.value = false
	permissions.value = share.permissions
	expiresAt.value = share.expiresAt ? new Date(share.expiresAt) : null
	maxDownloads.value = share.maxDownloads !== null ? String(share.maxDownloads) : ''
	sendEmail.value = !share.emailSent
}

function reset(): void {
	email.value = ''
	emailTouched.value = false
	permissions.value = modeOptions.value[0]?.value ?? ShareMode.ReadOnly
	expiresAt.value = null
	maxDownloads.value = ''
	sendEmail.value = true
}

async function onSubmit(): Promise<void> {
	emailTouched.value = true
	if (!isFormValid.value || submitting.value) {
		return
	}
	submitting.value = true
	try {
		const cap = props.isFolder ? null : parseCap(maxDownloads.value)
		const form: ShareForm = {
			email: email.value.trim(),
			permissions: props.isFolder ? permissions.value : ShareMode.ReadOnly,
			expiresAt: expiresAt.value ? endOfDay(expiresAt.value).toISOString() : null,
			maxDownloads: cap,
			// When the toggle is hidden, honour the policy: opt-out disallowed means
			// always notify; a global disable is handled server-side regardless.
			sendEmail: showEmailToggle.value ? sendEmail.value : true,
		}
		const ok = props.editing !== null
			? await props.update(props.editing.id, form)
			: await props.create(form)
		if (ok) {
			// Leaving edit mode (or a create success) resets the fields via the
			// `editing` watcher; a create success has no edit state so reset directly.
			if (props.editing !== null) {
				props.cancelEdit()
			} else {
				reset()
			}
		}
	} finally {
		submitting.value = false
	}
}

/**
 * endOfDay returns the last instant of the picked day, so a share stays usable
 * through the whole chosen date instead of lapsing at that day's midnight.
 */
function endOfDay(date: Date): Date {
	const d = new Date(date)
	d.setHours(23, 59, 59, 999)
	return d
}

function parseCap(value: string): number | null {
	const n = Number.parseInt(value, 10)
	return Number.isFinite(n) && n >= 1 ? n : null
}
</script>

<style scoped>
.atrium-share-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-block-end: 16px;
}

.atrium-share-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.atrium-share-form__label {
	font-weight: 600;
	font-size: 0.9em;
}

.atrium-share-form__static {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.atrium-share-form__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.atrium-share-form__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
