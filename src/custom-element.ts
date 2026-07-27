/**
 * Defines the custom element that hosts the Atrium sharing section. The new
 * Nextcloud sharing-sidebar API (@nextcloud/sharing registerSidebarSection) takes
 * a custom-element tag, not a Vue component, which deliberately decouples the
 * section from Nextcloud's own Vue runtime. We therefore wrap our Vue 2.7 app in
 * a thin custom element: it mounts the app into its own light DOM (so Nextcloud's
 * theming CSS variables still apply) and forwards the reactive `node` property
 * the sidebar sets on selection changes.
 */
import Vue, { markRaw } from 'vue'
import type { Node as FileNode } from '@nextcloud/files'

import AtriumSection from './AtriumSection.vue'

/** Must be prefixed with the app namespace (`oca_`) per the sidebar API. */
export const SECTION_ELEMENT = 'oca_atrium_secureshare-sharing_section'

class AtriumSharingSection extends HTMLElement {
	private vm?: Vue
	private state = Vue.observable<{ node: FileNode | null }>({ node: null })

	set node(node: FileNode | null) {
		// markRaw keeps Vue's reactivity on the `node` reference (so selection
		// changes re-render) but stops it from deep-observing the @nextcloud/files
		// Node: that would wrap the Node's internal attributes Proxy and break
		// node.update()/event-bus emit, which the section relies on to refresh the
		// native "Shared" indicator live.
		this.state.node = node ? markRaw(node) : null
	}

	get node(): FileNode | null {
		return this.state.node
	}

	connectedCallback(): void {
		if (this.vm) {
			return
		}
		const mount = document.createElement('div')
		this.appendChild(mount)
		const state = this.state
		this.vm = new Vue({
			render: (h) => (state.node ? h(AtriumSection, { props: { node: state.node } }) : h()),
		})
		this.vm.$mount(mount)
	}

	disconnectedCallback(): void {
		this.vm?.$destroy()
		this.vm = undefined
		this.innerHTML = ''
	}
}

export function registerSectionElement(): void {
	if (!window.customElements.get(SECTION_ELEMENT)) {
		window.customElements.define(SECTION_ELEMENT, AtriumSharingSection)
	}
}
