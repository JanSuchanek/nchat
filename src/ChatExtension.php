<?php

declare(strict_types=1);

namespace NChat;

use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use NChat\Service\ChatService;
use NChat\Service\WebSocketPublisher;
use NChat\Storage\ChatStorageInterface;
use NChat\Storage\DbalChatStorage;

/**
 * NChat Nette DI extension.
 *
 * Register in your config:
 *   extensions:
 *       nchat: NChat\ChatExtension
 *
 *   nchat:
 *       websocket:
 *           host: soketi
 *           port: 6001
 *       appId: my-app-id
 *           key: my-app-key
 *           secret: my-app-secret
 *       storage: NChat\Storage\DbalChatStorage  # or your own implementation
 *       ai:
 *           enabled: false
 *           responder: null
 */
class ChatExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'websocket' => Expect::structure([
				'host' => Expect::string('soketi'),
				'port' => Expect::int(6001),
				'appId' => Expect::string('app-id'),
				'key' => Expect::string('app-key'),
				'secret' => Expect::string('app-secret'),
			]),
			'storage' => Expect::string(DbalChatStorage::class),
			'ai' => Expect::structure([
				'enabled' => Expect::bool(false),
				'responder' => Expect::string()->nullable()->default(null),
			]),
		]);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		// WebSocket Publisher
		$builder->addDefinition($this->prefix('publisher'))
			->setFactory(WebSocketPublisher::class, [
				$config->websocket->appId,
				$config->websocket->key,
				$config->websocket->secret,
				$config->websocket->host,
				$config->websocket->port,
			]);

		// Storage (database abstraction)
		$builder->addDefinition($this->prefix('storage'))
			->setType(ChatStorageInterface::class)
			->setFactory($config->storage);

		// Chat Service
		$builder->addDefinition($this->prefix('chatService'))
			->setFactory(ChatService::class);

		// AI Responder (optional)
		if ($config->ai->enabled && $config->ai->responder !== null) {
			$builder->addDefinition($this->prefix('aiResponder'))
				->setType($config->ai->responder);
		}
	}
}
