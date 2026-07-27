import type { Node as FileNode } from '@nextcloud/files'
import { registerSidebarSection } from '@nextcloud/sharing/ui'

import { registerSectionElement, SECTION_ELEMENT } from './custom-element'
import { registerAtriumSharesView } from './shares-view'

registerSectionElement()

// Register the "Shared via {brand}" navigation view under the native Shares
// section. It is independent of the sidebar section above; a failure here must
// never break the sidebar, so a rejected registration is caught and ignored.
registerAtriumSharesView().catch(() => { /* view is optional; keep the sidebar working */ })

registerSidebarSection({
	id: 'atrium-external-share',
	element: SECTION_ELEMENT,
	// Order draws higher values first; a low value keeps the Atrium section
	// below the native internal sharing. The section title is rendered inside
	// the element (the section API carries no name field).
	order: 10,
	enabled: (node: FileNode) => Boolean(node?.fileid),
})
