<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCA\AbsCompat\Service\BookmarkService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\ProgressService;
use OCA\AbsCompat\Service\Serializer;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class AuthController extends AbsBaseController {
	public function __construct(
		IRequest $request,
		TokenService $tokens,
		LibraryService $library,
		private IUserManager $userManager,
		private ProgressService $progress,
		private IFactory $l10nFactory,
		private IProvider $tokenProvider,
		private IRegistry $twoFactorRegistry,
		private BookmarkService $bookmarks,
	) {
		parent::__construct($request, $tokens, $library);
	}

	private function language(): string {
		$lang = strtolower(str_replace('_', '-', $this->l10nFactory->findLanguage()));
		return $lang === 'en' ? 'en-us' : $lang;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/status')]
	public function status(): JSONResponse {
		return new JSONResponse([
			'app' => 'audiobookshelf',
			'serverVersion' => Application::ABS_VERSION,
			'isInit' => true,
			'language' => $this->language(),
			'authMethods' => ['local'],
			'authFormData' => ['authLoginCustomMessage' => null],
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/ping')]
	public function ping(): JSONResponse {
		return new JSONResponse(['success' => true]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/healthcheck')]
	public function healthcheck(): JSONResponse {
		return new JSONResponse([]);
	}

	private function loginPayload(IUser $user): array {
		$uid = $user->getUID();
		$libs = $this->library->getLibraries($uid);
		return [
			'user' => Serializer::user($uid, $user->getDisplayName(), $user->getEMailAddress(), $this->progress->allForUser($uid), $this->bookmarks->allForUser($uid)),
			'userDefaultLibraryId' => $libs[0]['id'] ?? null,
			'serverSettings' => Serializer::serverSettings($this->language()),
			'ereaderDevices' => [],
			'Source' => 'nextcloud',
		];
	}

	private function deviceName(): string {
		$ua = $this->request->getHeader('User-Agent');
		if (preg_match('/audiobookshelf|okhttp|abs/i', $ua)) {
			return 'Audiobookshelf app';
		}
		return $ua !== '' ? mb_substr($ua, 0, 120) : 'Device login';
	}

	/**
	 * Accepts a Nextcloud app password (works with two-factor auth), or the account password
	 * only when the account has no second factor: a plain password must not bypass 2FA.
	 */
	private function verifyCredentials(string $username, string $password): ?IUser {
		try {
			$token = $this->tokenProvider->getToken($password);
			if ($token->getType() === IToken::PERMANENT_TOKEN
				&& (mb_strtolower($token->getLoginName()) === mb_strtolower($username) || $token->getUID() === $username)) {
				$user = $this->userManager->get($token->getUID());
				if ($user !== null && $user->isEnabled()) {
					return $user;
				}
			}
		} catch (InvalidTokenException) {
			// Not an app password; try the account password.
		}
		$user = $this->userManager->checkPassword($username, $password);
		if (!$user instanceof IUser || !$user->isEnabled()) {
			return null;
		}
		if (in_array(true, $this->twoFactorRegistry->getProviderStates($user), true)) {
			return null;
		}
		return $user;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'abscompat_login')]
	#[FrontpageRoute(verb: 'POST', url: '/login')]
	public function login(): JSONResponse {
		$body = $this->body();
		$username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
		$password = is_string($body['password'] ?? null) ? $body['password'] : '';
		$user = $username !== '' && $password !== '' ? $this->verifyCredentials($username, $password) : null;
		if ($user === null) {
			$res = new JSONResponse(['error' => 'Invalid username or password'], Http::STATUS_UNAUTHORIZED);
			$res->throttle(['user' => $username]);
			return $res;
		}
		$tokens = $this->tokens->createLoginTokens($user->getUID(), $this->deviceName());
		$payload = $this->loginPayload($user);
		$payload['user']['accessToken'] = $tokens['accessToken'];
		// ABS only returns the refresh token in the body when the client asks for it.
		$payload['user']['refreshToken'] = strtolower($this->request->getHeader('x-return-tokens')) === 'true' ? $tokens['refreshToken'] : null;
		// Clients from before ABS 2.26 read user.token.
		$payload['user']['token'] = $tokens['accessToken'];
		return new JSONResponse($payload);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/authorize')]
	public function authorize(): JSONResponse {
		$uid = $this->currentUser();
		$user = $uid !== null ? $this->userManager->get($uid) : null;
		if ($user === null) {
			return $this->unauthorized();
		}
		return new JSONResponse($this->loginPayload($user));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'abscompat_refresh')]
	#[FrontpageRoute(verb: 'POST', url: '/auth/refresh')]
	public function refresh(): JSONResponse {
		$secret = $this->request->getHeader('x-refresh-token');
		if ($secret === '') {
			$body = $this->body();
			$secret = is_string($body['refreshToken'] ?? null) ? $body['refreshToken'] : '';
		}
		$result = $secret !== '' ? $this->tokens->refresh($secret) : null;
		$user = $result !== null ? $this->userManager->get($result['userId']) : null;
		if ($result === null || $user === null) {
			$res = new JSONResponse(['error' => 'Invalid refresh token'], Http::STATUS_UNAUTHORIZED);
			$res->throttle();
			return $res;
		}
		$payload = $this->loginPayload($user);
		$payload['user']['accessToken'] = $result['accessToken'];
		$payload['user']['refreshToken'] = $result['refreshToken'];
		$payload['user']['token'] = $result['accessToken'];
		return new JSONResponse($payload);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/logout')]
	public function logout(): JSONResponse {
		$token = TokenService::tokenFromRequest($this->request, false);
		if ($token !== '') {
			$this->tokens->logout($token);
		}
		$refresh = $this->request->getHeader('x-refresh-token');
		if ($refresh !== '') {
			$this->tokens->logout($refresh);
		}
		return new JSONResponse([]);
	}
}
