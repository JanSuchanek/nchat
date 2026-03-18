<?php

declare(strict_types=1);

namespace NChat\Service;

use NChat\Bridge\AiResponderInterface;
use NChat\Storage\ChatStorageInterface;

/**
 * Core chat service — message sending, polling, groups, AI bot integration.
 *
 * Uses ChatStorageInterface for all database operations — works with any DB.
 */
class ChatService
{
	public function __construct(
		private ChatStorageInterface $storage,
		private WebSocketPublisher $publisher,
		private ?AiResponderInterface $aiResponder = null,
	) {}


	/**
	 * Send a chat message.
	 *
	 * @return array{id: int, wsChannel: string}
	 */
	public function sendMessage(
		int $userId,
		string $fullName,
		string $email,
		string $message,
		?int $recipientId = null,
		?int $groupId = null,
	): array {
		$msgId = $this->storage->saveMessage([
			'user_id' => $userId,
			'full_name' => $fullName,
			'email' => $email,
			'message' => $message,
			'recipient_id' => $recipientId,
			'group_id' => $groupId,
		]);

		// Publish via WebSocket
		$wsChannel = $this->getChatChannel($recipientId, $groupId, $userId);
		$msgData = [
			'id' => $msgId,
			'user_id' => $userId,
			'full_name' => $fullName,
			'message' => $message,
			'recipient_id' => $recipientId,
			'group_id' => $groupId,
			'time' => (new \DateTime())->format('H:i'),
			'is_dm' => $recipientId !== null,
		];

		try {
			$this->publisher->trigger($wsChannel, 'new-message', $msgData);
		} catch (\Throwable) {}

		// AI bot in DM
		if ($recipientId === 0 && $this->aiResponder !== null) {
			$this->triggerBotTyping($this->getChatChannel(0, null, $userId));
			$this->generateBotResponse($userId);
		}

		// AI bot in group
		if ($groupId !== null && $this->aiResponder !== null) {
			if ($this->storage->isGroupMember($groupId, 0)) {
				$this->triggerBotTyping($this->getChatChannel(null, $groupId, 0));
				$this->generateBotResponseForGroup($userId, $groupId);
			}
		}

		return ['id' => $msgId, 'wsChannel' => $wsChannel];
	}


	/**
	 * Poll messages with channel filtering and pagination.
	 */
	public function pollMessages(int $userId, int $sinceId = 0, int $beforeId = 0, ?string $channel = null): array
	{
		return $this->storage->fetchMessages($userId, [
			'since_id' => $sinceId,
			'before_id' => $beforeId,
			'channel' => $channel,
		]);
	}


	/**
	 * Get online users.
	 */
	public function getOnlineUsers(): array
	{
		return $this->storage->fetchOnlineUsers();
	}


	/**
	 * Get groups the user belongs to.
	 */
	public function getUserGroups(int $userId): array
	{
		return $this->storage->fetchUserGroups($userId);
	}


	/**
	 * Get groups with member IDs.
	 */
	public function getGroupsWithMembers(int $userId): array
	{
		return $this->storage->fetchGroupsWithMembers($userId);
	}


	/**
	 * Create a chat group.
	 */
	public function createGroup(string $name, int $creatorId, array $memberIds): array
	{
		return $this->storage->createGroup($name, $creatorId, $memberIds);
	}


	/**
	 * Authenticate a WebSocket channel subscription.
	 */
	public function authenticateChannel(string $socketId, string $channelName, int $userId, string $userName): string
	{
		$userData = null;
		if (str_starts_with($channelName, 'presence-')) {
			$userData = [
				'user_id' => (string) $userId,
				'user_info' => ['name' => $userName],
			];
		}

		return $this->publisher->auth($socketId, $channelName, $userData);
	}


	/**
	 * Get the WebSocket channel name for routing.
	 */
	public function getChatChannel(?int $recipientId, ?int $groupId, int $senderId): string
	{
		if ($groupId !== null) {
			return 'admin-chat-group-' . $groupId;
		}
		if ($recipientId !== null) {
			$ids = [$senderId, $recipientId];
			sort($ids);
			return 'admin-chat-dm-' . implode('-', $ids);
		}
		return 'admin-chat';
	}


	/**
	 * Get publisher instance (for JS config).
	 */
	public function getPublisher(): WebSocketPublisher
	{
		return $this->publisher;
	}


	// ── AI Bot ──

	private function triggerBotTyping(string $channel): void
	{
		$botName = $this->aiResponder?->getBotName() ?? '🤖 AI Asistent';
		try {
			$this->publisher->trigger($channel, 'client-typing', [
				'userId' => 0,
				'name' => $botName,
			]);
		} catch (\Throwable) {}
	}


	private function generateBotResponse(int $userId): void
	{
		$botName = $this->aiResponder?->getBotName() ?? '🤖 AI Asistent';
		try {
			$history = $this->storage->fetchConversationHistory($userId);
			$context = $this->formatConversationContext($history);
			$response = $this->aiResponder->respond($context);
			$this->saveBotMessage($response, $userId);
		} catch (\Throwable $e) {
			$this->saveBotMessage('⚠️ ' . $e->getMessage(), $userId);
		}
	}


	private function generateBotResponseForGroup(int $senderId, int $groupId): void
	{
		try {
			$history = $this->storage->fetchConversationHistory(0, $groupId);
			$context = $this->formatConversationContext($history);
			$response = $this->aiResponder->respond($context);
			$this->saveBotMessage($response, null, $groupId);
		} catch (\Throwable $e) {
			$this->saveBotMessage('⚠️ ' . $e->getMessage(), null, $groupId);
		}
	}


	private function formatConversationContext(array $history): string
	{
		$context = '';
		$botName = $this->aiResponder?->getBotName() ?? 'Asistent';
		foreach ($history as $h) {
			$role = (int) $h['user_id'] === 0 ? $botName : $h['full_name'];
			$context .= "{$role}: {$h['message']}\n";
		}
		return $context;
	}


	private function saveBotMessage(string $message, ?int $recipientId = null, ?int $groupId = null): void
	{
		$botName = $this->aiResponder?->getBotName() ?? '🤖 AI Asistent';

		$msgId = $this->storage->saveMessage([
			'user_id' => 0,
			'full_name' => $botName,
			'email' => 'ai@nchat.local',
			'message' => $message,
			'recipient_id' => $recipientId,
			'group_id' => $groupId,
		]);

		$wsChannel = $this->getChatChannel($recipientId, $groupId, 0);
		try {
			$this->publisher->trigger($wsChannel, 'new-message', [
				'id' => $msgId,
				'user_id' => 0,
				'full_name' => $botName,
				'message' => $message,
				'recipient_id' => $recipientId,
				'group_id' => $groupId,
				'time' => (new \DateTime())->format('H:i'),
				'is_dm' => $recipientId !== null,
			]);
		} catch (\Throwable) {}
	}
}
