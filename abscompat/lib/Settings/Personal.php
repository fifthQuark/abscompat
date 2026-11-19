<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Settings;

use OCA\AbsCompat\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private IURLGenerator $url,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		// Server URL the user enters in the apps: our /status route minus "/status". It follows the
		// instance's pretty-URL setting (with or without /index.php).
		$status = $this->url->linkToRouteAbsolute(Application::APP_ID . '.auth.status');
		$serverUrl = preg_replace('#/status$#', '', $status);
		$this->initialState->provideInitialState('server-url', $serverUrl);
		Util::addScript(Application::APP_ID, 'settings');
		Util::addStyle(Application::APP_ID, 'settings');
		return new TemplateResponse(Application::APP_ID, 'settings-personal', [], TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return 'abscompat';
	}

	public function getPriority(): int {
		return 10;
	}
}
