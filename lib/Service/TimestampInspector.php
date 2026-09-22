<?php

declare(strict_types=1);

namespace OCA\FilesPdfSign\Service;

use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * What a signed PDF says about WHEN it was signed, as opposed to when the signer
 * said it was signed.
 *
 * A PAdES signature carries two times. The visible block and pdfsig's "Signing
 * time" both come from the signing machine's own clock, asserted by the signer.
 * A signature made at the T level also carries a timestamp token from a third
 * party, and that is the one worth anything: it is what lets a signature made
 * today still be judged fairly in three years, when the signer's certificate has
 * long expired. Nothing in pdfsig's output mentions it.
 *
 * So this reads it out and answers three questions: is a timestamp there at all,
 * is it from an authority this deployment accepts, and was the signer's
 * certificate valid at the moment it attests. Deliberately self-contained — it
 * shares no code with the Notes app's timestamping, only the `timestamp_authority`
 * config block, so either app can be installed without the other.
 */
class TimestampInspector {
	/** DER of the OID id-aa-signatureTimeStampToken (1.2.840.113549.1.9.16.2.14). */
	private const TIMESTAMP_ATTRIBUTE = "\x06\x0b\x2a\x86\x48\x86\xf7\x0d\x01\x09\x10\x02\x0e";

	public function __construct(
		private IConfig         $config,
		private ITempManager    $temp,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Human-readable lines about the timestamps in a signed PDF, in the order
	 * the signatures appear. Empty when there is nothing to say.
	 *
	 * @return list<string>
	 */
	public function describe(string $pdf): array {
		$out = [];
		foreach ($this->signatureBlobs($pdf) as $i => $blob) {
			$n = count($this->signatureBlobs($pdf)) > 1 ? ' #' . ($i + 1) : '';
			$token = $this->extractToken($blob);
			if ($token === '') {
				$out[] = 'Signature' . $n . ' carries no trusted timestamp: the signing time it shows is the '
					. "signer's own clock.";
				continue;
			}
			$info = $this->tokenInfo($token);
			if ($info['time'] === '') {
				$out[] = 'Signature' . $n . ' carries a timestamp that could not be read.';
				continue;
			}
			$out[] = 'Signature' . $n . ' was timestamped by ' . ($info['tsa'] !== '' ? $info['tsa'] : 'an authority')
				. ' at ' . $info['time'] . '.';
			$out[] = '  ' . $this->pinVerdict($info['fingerprint']);
			$out[] = '  ' . $this->validityVerdict($blob, $info['time']);
		}
		return $out;
	}

	/**
	 * Every signature's CMS blob. A PDF stores it hex-encoded in /Contents, and
	 * pads it with zeros to the space the signer reserved.
	 *
	 * @return list<string>
	 */
	private function signatureBlobs(string $pdf): array {
		if (!preg_match_all('/\/Contents\s*<([0-9A-Fa-f]+)>/', $pdf, $m)) {
			return [];
		}
		$out = [];
		foreach ($m[1] as $hex) {
			$der = @hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex . '0');
			if ($der !== false && $der !== '') {
				$out[] = $der;
			}
		}
		return $out;
	}

	/**
	 * The timestamp token from a signature's unsigned attributes: find the
	 * attribute's identifier, then take the SET that follows it and the
	 * ContentInfo inside that SET. Hand-parsed because there is no PHP binding
	 * that reads CMS unsigned attributes, and shelling out cannot pick a nested
	 * structure out of a blob.
	 */
	private function extractToken(string $blob): string {
		$at = strpos($blob, self::TIMESTAMP_ATTRIBUTE);
		if ($at === false) {
			return '';
		}
		$pos = $at + strlen(self::TIMESTAMP_ATTRIBUTE);
		$set = $this->readTlv($blob, $pos);          // SET OF AttributeValue
		if ($set === null || $set['tag'] !== 0x31) {
			return '';
		}
		$inner = $this->readTlv($blob, $set['content']);  // ContentInfo SEQUENCE
		if ($inner === null || $inner['tag'] !== 0x30) {
			return '';
		}
		return substr($blob, $inner['start'], $inner['end'] - $inner['start']);
	}

	/**
	 * One DER tag-length-value at $pos.
	 *
	 * @return array{tag: int, start: int, content: int, end: int}|null
	 */
	private function readTlv(string $der, int $pos): ?array {
		if ($pos + 2 > strlen($der)) {
			return null;
		}
		$tag = ord($der[$pos]);
		$lenByte = ord($der[$pos + 1]);
		$headerLen = 2;
		if ($lenByte < 0x80) {
			$length = $lenByte;
		} else {
			$count = $lenByte & 0x7f;
			if ($count === 0 || $count > 4 || $pos + 2 + $count > strlen($der)) {
				return null; // indefinite or absurd: not something DSS emits
			}
			$length = 0;
			for ($i = 0; $i < $count; $i++) {
				$length = ($length << 8) | ord($der[$pos + 2 + $i]);
			}
			$headerLen = 2 + $count;
		}
		$end = $pos + $headerLen + $length;
		if ($end > strlen($der)) {
			return null;
		}
		return ['tag' => $tag, 'start' => $pos, 'content' => $pos + $headerLen, 'end' => $end];
	}

	/**
	 * What the token says, and who signed it.
	 *
	 * @return array{time: string, tsa: string, fingerprint: string}
	 */
	private function tokenInfo(string $token): array {
		$file = $this->temp->getTemporaryFile('.tst');
		file_put_contents($file, $token);
		$text = $this->run(['openssl', 'ts', '-reply', '-in', $file, '-token_in', '-text']);
		$info = [
			'time'        => $this->field($text, 'Time stamp'),
			'tsa'         => $this->field($text, 'TSA'),
			'fingerprint' => '',
		];
		// The authority's own certificate travels inside the token; its digest is
		// what the pin list names.
		$pem = $this->run(['openssl', 'pkcs7', '-inform', 'DER', '-in', $file, '-print_certs']);
		if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) {
			foreach ($m[0] as $certPem) {
				$parsed = openssl_x509_parse($certPem);
				$eku = is_array($parsed) ? (string)($parsed['extensions']['extendedKeyUsage'] ?? '') : '';
				if (stripos($eku, 'time stamping') !== false) {
					$digest = openssl_x509_fingerprint($certPem, 'sha256');
					$info['fingerprint'] = $digest === false ? '' : strtoupper($digest);
					break;
				}
			}
		}
		// "DirName:/CN=Timestamp Authority/O=sciencedata.dk" reads better without
		// the DirName: prefix.
		$info['tsa'] = trim(preg_replace('/^DirName:\s*/', '', $info['tsa']) ?? '');
		return $info;
	}

	/** Is that authority one this deployment accepts, and for that date? */
	private function pinVerdict(string $fingerprint): string {
		$block = $this->config->getSystemValue('timestamp_authority', []);
		$pins = (is_array($block) && isset($block['pins']) && is_array($block['pins'])) ? $block['pins'] : [];
		if ($pins === []) {
			return 'This server accepts any timestamp authority our certificate authority vouches for.';
		}
		if ($fingerprint === '') {
			return 'The authority that signed it could not be identified.';
		}
		foreach ($pins as $pin) {
			$listed = strtoupper((string)preg_replace('/[^0-9A-Fa-f]/', '',
				(string)($pin['fingerprint'] ?? '')));
			if ($listed === $fingerprint) {
				return 'It is an authority this server accepts.';
			}
		}
		return 'It is NOT an authority this server accepts.';
	}

	/** Was the signer's certificate valid at the moment the authority attests? */
	private function validityVerdict(string $blob, string $stampedAt): string {
		$when = strtotime($stampedAt);
		if ($when === false) {
			return 'The attested time could not be read.';
		}
		$file = $this->temp->getTemporaryFile('.p7');
		file_put_contents($file, $blob);
		$pem = $this->run(['openssl', 'pkcs7', '-inform', 'DER', '-in', $file, '-print_certs']);
		if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) {
			return "The signer's certificate could not be read.";
		}
		$parsed = openssl_x509_parse($m[0]);
		if (!is_array($parsed)) {
			return "The signer's certificate could not be read.";
		}
		$from = (int)($parsed['validFrom_time_t'] ?? 0);
		$to   = (int)($parsed['validTo_time_t'] ?? 0);
		if ($from !== 0 && $to !== 0 && $when >= $from && $when <= $to) {
			return "The signer's certificate was valid at that time"
				. ($to < time() ? ', though it has expired since.' : '.');
		}
		return "The signer's certificate was NOT valid at that time.";
	}

	/** Pull "Field: value" out of openssl's text output. */
	private function field(string $text, string $label): string {
		foreach (explode("\n", $text) as $line) {
			$line = trim($line);
			if (stripos($line, $label . ':') === 0) {
				return trim(substr($line, strlen($label) + 1));
			}
		}
		return '';
	}

	/** @param list<string> $argv */
	private function run(array $argv): string {
		$cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null';
		$out = [];
		$code = 0;
		exec($cmd, $out, $code);
		return implode("\n", $out);
	}
}
