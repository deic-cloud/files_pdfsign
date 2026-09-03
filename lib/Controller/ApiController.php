<?php

declare(strict_types=1);

namespace OCA\FilesPdfSign\Controller;

use OCA\FilesPdfSign\Service\PdfSignService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {

	public function __construct(
		string                 $appName,
		IRequest               $request,
		private PdfSignService $service,
		private IUserSession   $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	#[NoAdminRequired]
	public function sign(string $path = ''): DataResponse {
		if ($path === '') {
			return new DataResponse(['message' => 'No file specified.'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$saved = $this->service->sign($this->uid(), $path);
			return new DataResponse(['path' => $saved]);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	public function verify(string $path = ''): DataResponse {
		if ($path === '') {
			return new DataResponse(['message' => 'No file specified.'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$info = $this->service->verify($this->uid(), $path);
			return new DataResponse(['info' => $info]);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}
}
