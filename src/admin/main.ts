import Vue from 'vue'

import AdminSettings from './AdminSettings.vue'

document.addEventListener('DOMContentLoaded', () => {
	const el = document.getElementById('atrium-admin-settings')
	if (el) {
		new Vue({ render: (h) => h(AdminSettings) }).$mount(el)
	}
})
