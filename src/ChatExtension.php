<?php

declare(strict_types=1);

namespace NChat;

use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use NChat\Service\ChatService;
use NChat\Service\FileUploader;
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
 *           appId: my-app-id
 *           key: my-app-key
 *           secret: my-app-secret
 *       storage: NChat\Storage\DbalChatStorage
 *       upload:
 *           dir: %wwwDir%/uploads/chat
 *           maxSize: 10485760
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
			'upload' => Expect::structure([
				'dir' => Expect::string()->nullable()->default(null),
				'maxSize' => Expect::int(10_485_760),
				'allowedTypes' => Expect::listOf('string')->default([]),
			]),
			'ai' => Expect::structure([
				'enabled' => Expect::bool(false),
				'responder' => Expect::string()->nullable()->default(null),
			]),
		]);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var object $config */
		$config = $this->getConfig();

		// WebSocket Publisher
		$builder->addDefinition($this->prefix('publisher'))
			->setFactory(WebSocketPublisher::class, [
				$config->websocket->appId, // @phpstan-ignore property.notFound, property.nonObject
				$config->websocket->key, // @phpstan-ignore property.notFound, property.nonObject
				$config->websocket->secret, // @phpstan-ignore property.notFound, property.nonObject
				$config->websocket->host, // @phpstan-ignore property.notFound, property.nonObject
				$config->websocket->port, // @phpstan-ignore property.notFound, property.nonObject
			]);

		// Storage (database abstraction)
		$builder->addDefinition($this->prefix('storage'))
			->setType(ChatStorageInterface::class)
			->setFactory($config->storage); // @phpstan-ignore argument.type, property.notFound

		// File Uploader (optional — only if dir is configured)
		/** @var mixed $uploadConfig */
		$uploadConfig = $config->upload ?? null;
		$uploadDir = is_object($uploadConfig) ? ($uploadConfig->dir ?? null) : null;
		if (is_string($uploadDir)) {
			$maxSize = is_object($uploadConfig) ? ($uploadConfig->maxSize ?? 10_485_760) : 10_485_760;
			$allowedTypes = is_object($uploadConfig) ? ($uploadConfig->allowedTypes ?? []) : [];
			$builder->addDefinition($this->prefix('fileUploader'))
				->setFactory(FileUploader::class, [$uploadDir, $maxSize, $allowedTypes]);
		}

		// Chat Service
		$builder->addDefinition($this->prefix('chatService'))
			->setFactory(ChatService::class);

		// AI Responder (optional)
		/** @var mixed $aiConfig */
		$aiConfig = $config->ai ?? null;
		$aiEnabled = is_object($aiConfig) && $aiConfig->enabled; // @phpstan-ignore property.notFound
		$aiClass = is_object($aiConfig) ? ($aiConfig->responder ?? null) : null;
		if ($aiEnabled && is_string($aiClass)) {
			$builder->addDefinition($this->prefix('aiResponder'))
				->setType($aiClass);
		}
	}
}
