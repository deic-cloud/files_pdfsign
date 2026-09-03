<?php
declare(strict_types=1);
namespace OCA\FilesPdfSign\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesPdfSign\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		Util::addScript(Application::APP_ID, 'files-action');
	}
}
