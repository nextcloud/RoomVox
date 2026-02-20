<?php

declare(strict_types=1);

namespace OCA\RoomVox\Settings;

use OCA\RoomVox\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'roomvox-personal');
        Util::addStyle(Application::APP_ID, 'style');

        return new TemplateResponse(Application::APP_ID, 'personal');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
