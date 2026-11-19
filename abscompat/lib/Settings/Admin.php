<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Settings;

use OCA\AbsCompat\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'settings');
		return new TemplateResponse(Application::APP_ID, 'settings-admin', [], TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return 'abscompat';
	}

	public function getPriority(): int {
		return 10;
	}
}
