<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/** The library page in Nextcloud's top menu. */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private IURLGenerator $url,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('settings-url', $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID]));
		foreach (['tags', 'uploader', 'library'] as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
		Util::addStyle(Application::APP_ID, 'library');
		return new TemplateResponse(Application::APP_ID, 'library', ['pageTitle' => 'Audiobooks']);
	}
}
