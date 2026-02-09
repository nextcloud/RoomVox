<?php

declare(strict_types=1);

namespace OCA\ResaVox\Controller;

use OCA\ResaVox\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Main app page — loads the Vue admin UI
     */
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'resavox-main');
        Util::addStyle(Application::APP_ID, 'style');

        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
