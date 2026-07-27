<!--
  AdminSettings is the admin configuration form. Validation is authoritative on
  the server; this form only guides input and surfaces the server's verdict.
-->
<template>
	<div class="atrium-admin">
		<NcSettingsSection
			:name="t('atrium_secureshare', 'Core trust')"
			:description="t('atrium_secureshare', 'The Atrium core signs every request to this app with its private key. Install the matching public key (ES256 / P-256, PEM) so the app can verify it. The core logs this key and the exact command at startup.')">
			<NcNoteCard v-if="config.keyFingerprint" type="success">
				{{ t('atrium_secureshare', 'Trust configured') }} — {{ config.keyFingerprint }}
			</NcNoteCard>
			<NcNoteCard v-else type="warning">
				{{ t('atrium_secureshare', 'No core public key installed — the app cannot verify core requests.') }}
			</NcNoteCard>

			<NcTextArea
				v-model="config.corePublicKey"
				class="atrium-admin__key"
				:label="t('atrium_secureshare', 'Core public key (PEM)')"
				:placeholder="'-----BEGIN PUBLIC KEY-----'"
				rows="5" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('atrium_secureshare', 'Portal')"
			:description="t('atrium_secureshare', 'The Atrium portal recipients are sent to. Leave empty to use this server\'s base URL.')">
			<NcTextField
				v-model="config.portalUrl"
				type="url"
				:label="t('atrium_secureshare', 'Portal URL')"
				placeholder="https://atrium.example.com" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('atrium_secureshare', 'Email invitations')"
			:description="t('atrium_secureshare', 'Control whether recipients are notified by email when a share is created.')">
			<NcCheckboxRadioSwitch v-model="config.emailEnabled" type="switch">
				{{ t('atrium_secureshare', 'Send invitation emails') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="config.emailOptOutAllowed"
				:disabled="!config.emailEnabled"
				type="switch">
				{{ t('atrium_secureshare', 'Allow owners to skip notifying the recipient') }}
			</NcCheckboxRadioSwitch>
			<p class="atrium-admin__hint">
				{{ t('atrium_secureshare', 'When skipping is not allowed, the recipient is always notified even if the owner unchecks the option.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('atrium_secureshare', 'Share policy')"
			:description="t('atrium_secureshare', 'Restrict which sharing modes owners may use and how long shares may last. Enforced on the server.')">
			<fieldset class="atrium-admin__modes">
				<NcCheckboxRadioSwitch
					v-for="mode in allModes"
					:key="mode.value"
					:model-value="config.allowedModes.includes(mode.value)"
					@update:model-value="toggleMode(mode.value, $event)">
					{{ mode.label }}
				</NcCheckboxRadioSwitch>
				<p v-if="config.allowedModes.length === 0" class="atrium-admin__bad">
					{{ t('atrium_secureshare', 'At least one mode must be allowed.') }}
				</p>
			</fieldset>

			<NcTextField
				:model-value="String(config.maxShareDurationDays ?? 0)"
				type="number"
				min="0"
				:label="t('atrium_secureshare', 'Maximum share duration in days (0 = unlimited)')"
				@update:model-value="onMaxDurationInput" />
			<p class="atrium-admin__hint">
				{{ t('atrium_secureshare', 'When set, every share must carry an expiry within this many days.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('atrium_secureshare', 'Retention')"
			:description="t('atrium_secureshare', 'How long an expired or exhausted share stays visible to its owner in the file sidebar before it is permanently deleted. During this window the owner can reactivate it by editing the expiry or download limit.')">
			<NcTextField
				:model-value="String(config.retentionDays ?? 0)"
				type="number"
				min="0"
				:label="t('atrium_secureshare', 'Retention window in days (0 = delete immediately)')"
				@update:model-value="onRetentionInput" />
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('atrium_secureshare', 'Branding')"
			:description="t('atrium_secureshare', 'Interim brand name shown in the sharing sidebar. This is a temporary app-local field until the Atrium core exposes a central brand name.')">
			<NcTextField
				v-model="config.whitelabelName"
				:label="t('atrium_secureshare', 'Brand name')"
				placeholder="Atrium" />
		</NcSettingsSection>

		<div class="atrium-admin__save">
			<NcButton type="primary" :disabled="saving || config.allowedModes.length === 0" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('atrium_secureshare', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'

import { folderModes } from '../permissions'
import { useAdminSettings } from './useAdminSettings'

const { config, saving, save } = useAdminSettings()

const allModes = computed(() => folderModes())

function toggleMode(value: number, checked: boolean): void {
	const set = new Set(config.allowedModes)
	if (checked) {
		set.add(value)
	} else {
		set.delete(value)
	}
	config.allowedModes = [...set].sort((a, b) => a - b)
}

function onMaxDurationInput(value: string): void {
	const n = Number.parseInt(value, 10)
	config.maxShareDurationDays = Number.isFinite(n) && n > 0 ? n : 0
}

function onRetentionInput(value: string): void {
	const n = Number.parseInt(value, 10)
	config.retentionDays = Number.isFinite(n) && n > 0 ? n : 0
}
</script>

<style scoped>
.atrium-admin__key {
	font-family: monospace;
	max-width: 640px;
}

.atrium-admin__modes {
	margin-block-end: 16px;
	border: none;
}

.atrium-admin__hint {
	color: var(--color-text-maxcontrast);
	margin-block-start: 4px;
}

.atrium-admin__bad {
	color: var(--color-error);
}

.atrium-admin__save {
	margin-block-start: 8px;
}
</style>
