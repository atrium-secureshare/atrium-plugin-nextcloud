import { createApp } from 'vue'

import AdminSettings from './AdminSettings.vue'

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('atrium-admin-settings')
	if (el) {
		createApp(AdminSettings).mount(el)
	}
})
