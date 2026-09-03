/** Minimal modal showing the pod's signature-verification output (monospace). */
import { translate as t } from '@nextcloud/l10n'

export function openVerifyDialog(name, info) {
	const overlay = document.createElement('div')
	overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10000;display:flex;align-items:center;justify-content:center;'
	const box = document.createElement('div')
	box.style.cssText = 'background:var(--color-main-background,#fff);color:var(--color-main-text,#222);border-radius:var(--border-radius-large,10px);max-width:640px;width:92%;max-height:80vh;overflow:auto;padding:18px 22px;box-shadow:0 2px 24px rgba(0,0,0,.4);'
	const h = document.createElement('h3')
	h.textContent = t('files_pdfsign', 'Signatures — {name}', { name })
	h.style.cssText = 'margin:0 0 10px;'
	const pre = document.createElement('pre')
	pre.textContent = info
	pre.style.cssText = 'white-space:pre-wrap;font-size:13px;background:var(--color-background-hover,#f5f5f5);border:1px solid var(--color-border,#ddd);border-radius:6px;padding:10px 12px;margin:0 0 12px;'
	const close = document.createElement('button')
	close.textContent = t('files_pdfsign', 'Close')
	close.className = 'button'
	close.addEventListener('click', () => overlay.remove())
	overlay.addEventListener('click', (e) => { if (e.target === overlay) { overlay.remove() } })
	box.appendChild(h); box.appendChild(pre); box.appendChild(close)
	overlay.appendChild(box)
	document.body.appendChild(overlay)
}
