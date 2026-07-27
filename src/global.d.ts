/**
 * `OCP.Files.Router` is a runtime global the Files app attaches to `window`;
 * there is no published type for it (native Nextcloud code itself references
 * it as an untyped global — see `apps/files_sharing/src/files_actions/openInFilesAction.ts`).
 */
export {}

declare global {
	interface Window {
		OCP: {
			Files: {
				Router: {
					goToRoute(
						route: string | null,
						params?: Record<string, string>,
						query?: Record<string, string | undefined>,
					): void
				}
			}
		}
	}
}
