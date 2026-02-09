<?php

declare(strict_types=1);

namespace OCA\RoomBooking\Settings;

use OCA\RoomBooking\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'roombooking-main');
        Util::addStyle(Application::APP_ID, 'style');

        return new TemplateResponse(Application::APP_ID, 'main');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
