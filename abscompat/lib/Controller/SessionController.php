<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\SessionService;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class SessionController extends AbsBaseController {
	public function __construct(
		IRequest $request,
		TokenService $tokens,
		LibraryService $library,
		private SessionService $sessions,
	) {
		parent::__construct($request, $tokens, $library);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/session/local')]
	public function local(): Response {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$body = $this->body();
		$r = $this->sessions->syncLocal($uid, $body, is_array($body['deviceInfo'] ?? null) ? $body['deviceInfo'] : []);
		if (!$r['success']) {
			return new DataDisplayResponse($r['error'] ?? 'Error', Http::STATUS_INTERNAL_SERVER_ERROR, ['Content-Type' => 'text/plain']);
		}
		return new DataDisplayResponse('OK', Http::STATUS_OK, ['Content-Type' => 'text/plain']);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/session/local-all')]
	public function localAll(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$body = $this->body();
		$dev = is_array($body['deviceInfo'] ?? null) ? $body['deviceInfo'] : [];
		$results = [];
		foreach ((array)($body['sessions'] ?? []) as $s) {
			if (is_array($s)) {
				$results[] = $this->sessions->syncLocal($uid, $s, $dev);
			}
		}
		return new JSONResponse(['results' => $results]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/session/{id}/sync')]
	public function sync(string $id): Response {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if (!$this->sessions->sync($uid, $id, $this->body(), false)) {
			return $this->notFound('Session not found');
		}
		return new JSONResponse([]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/session/{id}/close')]
	public function close(string $id): Response {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		// Closing an unknown session is not an error for the client.
		$this->sessions->sync($uid, $id, $this->body(), true);
		return new JSONResponse([]);
	}
}
