/**
 * Defines the custom element that hosts the Atrium sharing section. The new
 * Nextcloud sharing-sidebar API (@nextcloud/sharing registerSidebarSection) takes
 * a custom-element tag, not a Vue component, which deliberately decouples the
 * section from Nextcloud's own Vue runtime. We therefore wrap our Vue app in
 * a thin custom element: it mounts the app into its own light DOM (so Nextcloud's
 * theming CSS variables still apply) and forwards the reactive `node` property
 * the sidebar sets on selection changes.
 */
import { createApp, h, shallowReactive, type App } from 'vue'
import type { Node as FileNode } from '@nextcloud/files'

import AtriumSection from './AtriumSection.vue'

/** Must be prefixed with the app namespace (`oca_`) per the sidebar API. */
export const SECTION_ELEMENT = 'oca_atrium_secureshare-sharing_section'

class AtriumSharingSection extends HTMLElement {
	private app?: App
	// Only the top-level `node` reference is reactive (so a selection change
	// re-renders); shallowReactive deliberately does NOT deep-proxy the value.
	// That matters: deep-observing the @nextcloud/files Node would wrap its
	// internal attributes Proxy and break node.update()/event-bus emit, which the
	// section relies on to refresh the native "Shared" indicator live.
	private state = shallowReactive<{ node: FileNode | null }>({ node: null })

	set node(node: FileNode | null) {
		this.state.node = node
	}

	get node(): FileNode | null {
		return this.state.node
	}

	connectedCallback(): void {
		if (this.app) {
			return
		}
		const mount = document.createElement('div')
		this.appendChild(mount)
		const state = this.state
		this.app = createApp({
			render: () => (state.node ? h(AtriumSection, { node: state.node }) : null),
		})
		this.app.mount(mount)
	}

	disconnectedCallback(): void {
		this.app?.unmount()
		this.app = undefined
		this.innerHTML = ''
	}
}

export function registerSectionElement(): void {
	if (!window.customElements.get(SECTION_ELEMENT)) {
		window.customElements.define(SECTION_ELEMENT, AtriumSharingSection)
	}
}
