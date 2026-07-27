/** Lets TypeScript resolve `*.vue` single-file component imports. */
declare module '*.vue' {
	import type Vue from 'vue'
	const component: typeof Vue
	export default component
}
