<?php

declare(strict_types=1);

namespace OCA\FilesPdfSign\Service;

use OCP\Files\IRootFolder;
use OCP\IConfig;

/**
 * Talks to the stateless pdf_sign service pod (open-pdf-sign / PAdES-LTV).
 *
 * The pod PULLS the material it needs — the user's private key, public
 * certificate and the PDF — from the user's home silo, acting on the user's
 * behalf (cloud-owned pod on the user-pod VLAN → IpAuthBackend impersonation;
 * getkey/getcert legacy endpoints; the impersonated /files WebDAV). So this
 * service only TRIGGERS the pod: it passes the user id, the home-server URL the
 * pod should pull from, and the file's dir + name — exactly the old
 * files_pdfviewer/pdf_signature_actions.php contract, so the pod is unchanged.
 *
 * sign()   → returns the signed PDF bytes (saved by the controller).
 * verify() → returns the pod's signature-info text.
 */
class PdfSignService {

	public function __construct(
		private IConfig         $config,
		private IRootFolder     $rootFolder,
	) {
	}

	public function isConfigured(): bool {
		return $this->apiUrl() !== '';
	}

	private function apiUrl(): string {
		return rtrim((string)$this->config->getSystemValue('pdfsign_api_url', ''), '/');
	}

	/**
	 * The URL the POD should pull the user's files/key/cert from. The request is
	 * always served BY the user's home silo — files_sharding redirects the user
	 * there before this controller runs — so that URL is simply this node's own,
	 * no lookup needed. (getUserServer() reads the master-authoritative
	 * user_servers table and returns null on a silo for its own users anyway.)
	 * The pod reaches it on the user-pod network, keeping its source IP on
	 * uservlannet, where getkey is allowed.
	 */
	private function homeServerUrl(): string {
		return rtrim((string)$this->config->getSystemValue('overwrite.cli.url', ''), '/');
	}

	/** GET the pod with the given action + params; returns [httpCode, body, contentType]. */
	private function callPod(string $uid, string $action, string $dir, string $filename): array {
		$api = $this->apiUrl();
		if ($api === '') {
			throw new \RuntimeException('PDF signing service is not configured (pdfsign_api_url).');
		}
		$q = http_build_query([
			'action'          => $action,
			'user'            => $uid,
			'user_server_url' => $this->homeServerUrl(),
			'dir'             => $dir,
			'filename'        => $filename,
		]);
		$ch = curl_init($api . '?' . $q);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,   // the pod serves a self-signed cert
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 120,      // signing a large PDF can take a while
		]);
		$body = curl_exec($ch);
		if ($body === false) {
			$err = curl_error($ch);
			curl_close($ch);
			throw new \RuntimeException('Could not reach the PDF signing service: ' . $err);
		}
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);
		return [$code, (string)$body, $type];
	}

	/**
	 * Sign the PDF at $path (relative to the user's files root) and save the
	 * signed copy next to it as "<name>.signed.pdf". Returns the saved path.
	 */
	public function sign(string $uid, string $path): string {
		[$dir, $filename] = $this->splitPath($path);
		[$code, $body, $type] = $this->callPod($uid, 'sign', $dir, $filename);

		if ($code !== 200 || stripos($type, 'application/pdf') === false) {
			throw new \RuntimeException($this->extractError($body) ?: 'Signing failed (HTTP ' . $code . ').');
		}
		if ($body === '' ) {
			throw new \RuntimeException('The signing service returned an empty document.');
		}

		$base = preg_replace('/\.pdf$/i', '', $filename);
		$outName = $base . '.signed.pdf';
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder = $dir === '' ? $userFolder : $userFolder->get($dir);
		if ($folder->nodeExists($outName)) {
			$folder->get($outName)->putContent($body);
		} else {
			$folder->newFile($outName, $body);
		}
		return trim($dir . '/' . $outName, '/');
	}

	/** Verify signatures on the PDF; returns the pod's human-readable info text. */
	public function verify(string $uid, string $path): string {
		[$dir, $filename] = $this->splitPath($path);
		[$code, $body] = $this->callPod($uid, 'verify', $dir, $filename);
		$json = json_decode($body, true);
		if (is_array($json) && ($json['status'] ?? '') === 'success') {
			return (string)($json['data']['info'] ?? '');
		}
		throw new \RuntimeException($this->extractError($body) ?: 'Verification failed (HTTP ' . $code . ').');
	}

	/** @return array{0:string,1:string} [dir, filename] */
	private function splitPath(string $path): array {
		$path = ltrim($path, '/');
		$dir  = trim((string)dirname($path), '/.');
		$dir  = $dir === '' || $dir === '.' ? '' : $dir;
		return [$dir, basename($path)];
	}

	private function extractError(string $body): string {
		$json = json_decode($body, true);
		if (is_array($json)) {
			return (string)($json['data']['message'] ?? $json['message'] ?? '');
		}
		return '';
	}
}
