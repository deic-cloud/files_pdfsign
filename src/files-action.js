/**
 * "Sign PDF" and "Verify signatures" Files actions, registered via the official
 * @nextcloud/files API (registerFileAction is not exposed to plain JS, so this
 * file is bundled). Both proxy to the app's OCS API, which triggers the
 * pdf_sign service pod. Only shown on .pdf files.
 */
import { registerFileAction } from '@nextcloud/files'
import { getClient, getDefaultPropfind, resultToNode, defaultRootPath } from '@nextcloud/files/dav'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess, showInfo } from './toast'
import { openVerifyDialog } from './dialog'

const OCS = (window.OC?.webroot || '') + '/ocs/v2.php/apps/files_pdfsign/api/v1'

// mdi: draw-pen / file-sign — a pen nib, reads as "sign"
const SIGN_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M9.75 20.85c1.78-.7 1.39-2.63.49-3.85-.89-1.25-2.12-2.11-3.36-2.94A9.8 9.8 0 0 1 4.54 12c-.28-.33-.85-.94-.27-1.06.59-.12 1.61.46 2.13.68.86.36 1.72.83 2.47 1.41l1.9-1.55C10.16 9.7 6.65 8.44 4.65 8.44c-1.03 0-2.06.28-2.53 1.28-.4.87-.2 1.9.26 2.71.94 1.65 2.75 2.53 4.09 3.76.28.26.8.74.53 1.17-.27.43-1.04.34-1.44.24-.86-.24-1.65-.69-2.38-1.19L2 18.06c1.2.72 2.53 1.32 3.95 1.53.75.11 1.55.13 2.28-.12.15-.05.35-.13.52-.22.28.7.72 1.29 1 .6M22 2h-2c-.55 0-1 .45-1 1v14.17l3-3V3c0-.55-.45-1-1-1M19 20l-1.44-1.16L13 22.53V24h1.47L19 20"/></svg>'
// mdi: shield-check — verify
const VERIFY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4m-2 16-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8"/></svg>'

function isPdf(node) {
	return node && (node.mime === 'application/pdf' || /\.pdf$/i.test(node.basename || node.displayname || ''))
}

function ocs(method, path, params) {
	const opts = {
		method,
		headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': window.OC.requestToken },
	}
	let url = OCS + path + '?format=json'
	if (method === 'POST') {
		opts.headers['Content-Type'] = 'application/x-www-form-urlencoded'
		opts.body = params
	} else if (params) {
		url = OCS + path + '?format=json&' + params
	}
	return fetch(url, opts).then((r) => r.json())
}

function pathOf(node) {
	// node.path is relative to the user's files root ("/dir/file.pdf")
	return (node.path || '').replace(/^\/+/, '')
}

async function signOne(node) {
	showInfo(t('files_pdfsign', 'Signing {name}… this can take a moment.', { name: node.basename }))
	try {
		const r = await ocs('POST', '/sign', 'path=' + encodeURIComponent(pathOf(node)))
		const d = r?.ocs?.data || {}
		if (r?.ocs?.meta?.statuscode === 200 && d.path) {
			showSuccess(t('files_pdfsign', 'Signed → {name}', { name: d.path.split('/').pop() }))
			// Surface the new .signed.pdf in the current view without a reload:
			// stat it over WebDAV and announce it on the event bus, exactly as
			// the Files app does after an upload.
			try {
				const stat = await getClient().stat(`${defaultRootPath}/${d.path}`, {
					details: true,
					data: getDefaultPropfind(),
				})
				emit('files:node:created', resultToNode(stat.data))
			} catch (e) {
				window.location.reload() // fallback: at least show the file
			}
		} else {
			showError(t('files_pdfsign', 'Signing failed') + ': ' + (d.message || 'unknown error'))
		}
	} catch (e) {
		showError(t('files_pdfsign', 'Signing failed') + ': ' + (e?.message || e))
	}
}

async function verifyOne(node) {
	try {
		const r = await ocs('GET', '/verify', 'path=' + encodeURIComponent(pathOf(node)))
		const d = r?.ocs?.data || {}
		if (r?.ocs?.meta?.statuscode === 200) {
			openVerifyDialog(node.basename, d.info || t('files_pdfsign', 'No signatures found.'))
		} else {
			showError(t('files_pdfsign', 'Verification failed') + ': ' + (d.message || 'unknown error'))
		}
	} catch (e) {
		showError(t('files_pdfsign', 'Verification failed') + ': ' + (e?.message || e))
	}
}

registerFileAction({
	id: 'files-pdfsign-sign',
	displayName: () => t('files_pdfsign', 'Sign PDF'),
	title: () => t('files_pdfsign', 'Digitally sign this PDF with your ScienceData certificate'),
	iconSvgInline: () => SIGN_ICON,
	enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length === 1 && isPdf(nodes[0]),
	exec: async ({ nodes }) => { await signOne(nodes[0]); return null },
	order: 30,
})

registerFileAction({
	id: 'files-pdfsign-verify',
	displayName: () => t('files_pdfsign', 'Verify signatures'),
	title: () => t('files_pdfsign', 'Show and verify the digital signatures on this PDF'),
	iconSvgInline: () => VERIFY_ICON,
	enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length === 1 && isPdf(nodes[0]),
	exec: async ({ nodes }) => { await verifyOne(nodes[0]); return null },
	order: 31,
})
