<?php

declare(strict_types=1);

namespace NChat\Presenter;

use NChat\Service\ChatService;
use NChat\Service\FileUploader;

/**
 * Chat presenter trait — drop-in actions for any Nette presenter.
 *
 * Usage: Add `use ChatPresenterTrait;` to your presenter.
 * ChatService will be auto-injected via Nette's inject mechanism.
 *
 * Required methods from the host presenter (Nette\Application\UI\Presenter):
 *   - getUser(), sendJson(), getHttpRequest(), getHttpResponse(), sendResponse()
 */
trait ChatPresenterTrait
{
	private ChatService $chatService;
	private ?FileUploader $fileUploader = null;


	public function injectChatService(ChatService $chatService): void
	{
		$this->chatService = $chatService;
	}


	public function injectFileUploader(?FileUploader $fileUploader = null): void
	{
		$this->fileUploader = $fileUploader;
	}


	/**
	 * List of chat action names that should be accessible to all logged-in users.
	 */
	public static function getChatActionNames(): array
	{
		return ['chatSend', 'chatPoll', 'chatAuth', 'chatOnline', 'chatGroupCreate', 'chatGroups', 'chatDownload', 'chatEdit', 'chatDelete', 'chatReaction', 'chatMarkRead', 'chatUnread'];
	}


	/**
	 * Send a chat message (POST).
	 * Params: message, to (DM recipient), group (group ID), file (optional attachment).
	 */
	public function actionChatSend(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$request = $this->getHttpRequest();
		$message = trim($request->getPost('message') ?? '');

		// Handle file upload
		$attachment = null;
		$file = $request->getFile('file');
		if ($file !== null && $this->fileUploader !== null) {
			try {
				$attachment = $this->fileUploader->upload($file);
			} catch (\RuntimeException $e) {
				$this->sendJson(['status' => 'error', 'message' => $e->getMessage()]);
				return;
			}
		}

		// Require message or attachment
		if ($message === '' && $attachment === null) {
			$this->sendJson(['status' => 'empty']);
			return;
		}

		$recipientId = $request->getPost('to');
		$recipientId = $recipientId !== null && $recipientId !== '' ? (int) $recipientId : null;
		$groupId = $request->getPost('group');
		$groupId = $groupId !== null && $groupId !== '' ? (int) $groupId : null;

		$userId = (int) $user->getId();
		$identity = $user->getIdentity();

		$result = $this->chatService->sendMessage(
			$userId,
			$identity->fullName ?? '',
			$identity->email ?? '',
			$message,
			$recipientId,
			$groupId,
			$attachment,
		);

		$this->sendJson(['status' => 'ok', 'id' => $result['id']]);
	}


	/**
	 * Poll for messages (GET).
	 * Params: since, before, channel.
	 */
	public function actionChatPoll(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$request = $this->getHttpRequest();
		$userId = (int) $user->getId();
		$sinceId = (int) ($request->getQuery('since') ?? 0);
		$beforeId = (int) ($request->getQuery('before') ?? 0);
		$channel = $request->getQuery('channel');

		$result = $this->chatService->pollMessages($userId, $sinceId, $beforeId, $channel);

		$this->sendJson([
			'status' => 'ok',
			'my_id' => $userId,
			'messages' => $result['messages'],
			'online' => $this->chatService->getOnlineUsers(),
			'groups' => $this->chatService->getUserGroups($userId),
			'has_more' => $result['has_more'],
		]);
	}


	/**
	 * Pusher-compatible auth endpoint for private/presence channels (POST).
	 */
	public function actionChatAuth(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->getHttpResponse()->setCode(403);
			$this->sendJson(['error' => 'Not authenticated']);
			return;
		}

		$request = $this->getHttpRequest();
		$socketId = $request->getPost('socket_id');
		$channelName = $request->getPost('channel_name');

		if (!$socketId || !$channelName) {
			$this->getHttpResponse()->setCode(400);
			$this->sendJson(['error' => 'Missing socket_id or channel_name']);
			return;
		}

		$identity = $user->getIdentity();
		$userName = $identity?->getData()['fullName'] ?? ('User ' . $user->getId());

		$authResponse = $this->chatService->authenticateChannel(
			$socketId,
			$channelName,
			(int) $user->getId(),
			$userName,
		);

		$this->getHttpResponse()->setContentType('application/json');
		$this->sendResponse(new \Nette\Application\Responses\TextResponse($authResponse));
	}


	/**
	 * Create a chat group (POST). Params: name, members.
	 */
	public function actionChatGroupCreate(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$request = $this->getHttpRequest();
		$name = trim($request->getPost('name') ?? '');
		$membersStr = trim($request->getPost('members') ?? '');
		if ($name === '' || $membersStr === '') {
			$this->sendJson(['status' => 'error', 'message' => 'name and members required']);
			return;
		}

		$memberIds = array_map('intval', explode(',', $membersStr));
		$result = $this->chatService->createGroup($name, (int) $user->getId(), $memberIds);

		$this->sendJson(['status' => 'ok', ...$result]);
	}


	/**
	 * List groups the current user belongs to (GET).
	 */
	public function actionChatGroups(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$groups = $this->chatService->getGroupsWithMembers((int) $user->getId());
		$this->sendJson(['status' => 'ok', 'groups' => $groups]);
	}


	/**
	 * Online users for chat sidebar (GET).
	 */
	public function actionChatOnline(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$online = $this->chatService->getOnlineUsers();
		$this->sendJson(['status' => 'ok', 'online' => $online]);
	}


	/**
	 * Download a chat attachment (GET). Params: path.
	 */
	public function actionChatDownload(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->getHttpResponse()->setCode(403);
			$this->sendJson(['error' => 'Not authenticated']);
			return;
		}

		$path = $this->getHttpRequest()->getQuery('path');
		if (!is_string($path) || $path === '' || $this->fileUploader === null) {
			$this->getHttpResponse()->setCode(400);
			$this->sendJson(['error' => 'Invalid request']);
			return;
		}

		// Security: prevent directory traversal
		if (str_contains($path, '..') || str_contains($path, "\0")) {
			$this->getHttpResponse()->setCode(400);
			$this->sendJson(['error' => 'Invalid path']);
			return;
		}

		if (!$this->fileUploader->fileExists($path)) {
			$this->getHttpResponse()->setCode(404);
			$this->sendJson(['error' => 'File not found']);
			return;
		}

		$absPath = $this->fileUploader->getAbsolutePath($path);
		$name = basename($path);
		$mime = mime_content_type($absPath) ?: 'application/octet-stream';

		$this->getHttpResponse()->setContentType($mime);
		$this->getHttpResponse()->setHeader('Content-Disposition', 'inline; filename="' . $name . '"');
		$this->getHttpResponse()->setHeader('Content-Length', (string) filesize($absPath));
		$this->sendResponse(new \Nette\Application\Responses\FileResponse($absPath, $name, $mime));
	}


	/**
	 * Edit own message (POST: message_id, text).
	 */
	public function actionChatEdit(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$request = $this->getHttpRequest();
		$messageId = (int) ($request->getPost('message_id') ?? 0);
		$newText = trim($request->getPost('text') ?? '');

		if ($messageId === 0 || $newText === '') {
			$this->sendJson(['status' => 'error', 'message' => 'Missing params']);
			return;
		}

		$ok = $this->chatService->editMessage($messageId, (int) $user->getId(), $newText);
		$this->sendJson(['status' => $ok ? 'ok' : 'error']);
	}


	/**
	 * Delete own message (POST: message_id).
	 */
	public function actionChatDelete(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$messageId = (int) ($this->getHttpRequest()->getPost('message_id') ?? 0);
		if ($messageId === 0) {
			$this->sendJson(['status' => 'error', 'message' => 'Missing message_id']);
			return;
		}

		$ok = $this->chatService->deleteMessage($messageId, (int) $user->getId());
		$this->sendJson(['status' => $ok ? 'ok' : 'error']);
	}


	/**
	 * Toggle emoji reaction (POST: message_id, emoji, action=add|remove).
	 */
	public function actionChatReaction(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$request = $this->getHttpRequest();
		$messageId = (int) ($request->getPost('message_id') ?? 0);
		$emoji = trim($request->getPost('emoji') ?? '');
		$action = $request->getPost('action') ?? 'add';

		if ($messageId === 0 || $emoji === '') {
			$this->sendJson(['status' => 'error', 'message' => 'Missing params']);
			return;
		}

		$userId = (int) $user->getId();
		if ($action === 'remove') {
			$this->chatService->removeReaction($messageId, $userId, $emoji);
		} else {
			$this->chatService->addReaction($messageId, $userId, $emoji);
		}

		$this->sendJson(['status' => 'ok']);
	}


	/**
	 * Mark messages as read (POST: message_ids=[...]).
	 */
	public function actionChatMarkRead(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$raw = $this->getHttpRequest()->getPost('message_ids');
		if (!is_string($raw) || $raw === '') {
			$this->sendJson(['status' => 'ok']);
			return;
		}

		/** @var list<int> $ids */
		$ids = array_map('intval', explode(',', $raw));
		$this->chatService->markRead((int) $user->getId(), $ids);
		$this->sendJson(['status' => 'ok']);
	}


	/**
	 * Fetch per-channel unread counts (GET).
	 */
	public function actionChatUnread(): void
	{
		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$this->sendJson(['status' => 'unauthorized']);
			return;
		}

		$counts = $this->chatService->getUnreadCounts((int) $user->getId());
		$this->sendJson(['status' => 'ok', 'unread' => $counts]);
	}
}
