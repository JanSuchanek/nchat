<?php

declare(strict_types=1);

namespace NChat\Storage;

use Doctrine\DBAL\Connection;

/**
 * DBAL (Doctrine) implementation of ChatStorageInterface.
 *
 * Works with MySQL, PostgreSQL, SQLite — uses standard SQL where possible.
 * Platform-specific queries are isolated in helper methods.
 */
class DbalChatStorage implements ChatStorageInterface
{
	public function __construct(
		private Connection $connection,
	) {}


	public function saveMessage(array $data): int
	{
		$this->connection->executeStatement(
			"INSERT INTO admin_chat_message (user_id, full_name, email, message, recipient_id, group_id, attachment_path, attachment_name, attachment_size, attachment_type, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
			[
				$data['user_id'],
				$data['full_name'],
				$data['email'],
				$data['message'],
				$data['recipient_id'] ?? null,
				$data['group_id'] ?? null,
				$data['attachment_path'] ?? null,
				$data['attachment_name'] ?? null,
				$data['attachment_size'] ?? null,
				$data['attachment_type'] ?? null,
			],
		);

		return (int) $this->connection->lastInsertId();
	}


	/**
	 * @return array{messages: list<array<string, mixed>>, has_more: bool}
	 */
	public function fetchMessages(int $userId, array $criteria = []): array
	{
		$sinceId = $criteria['since_id'] ?? 0;
		$beforeId = $criteria['before_id'] ?? 0;
		$channel = $criteria['channel'] ?? null;
		$limit = $criteria['limit'] ?? 50;

		// Build WHERE clause for channel filtering
		[$channelWhere, $channelParams] = $this->buildChannelFilter($userId, $channel);

		if ($beforeId > 0) {
			$messages = $this->connection->fetchAllAssociative(
				"SELECT id, user_id, full_name, message, recipient_id, group_id, attachment_path, attachment_name, attachment_size, attachment_type, created_at
				 FROM admin_chat_message
				 WHERE id < ? AND {$channelWhere}
				 ORDER BY id DESC LIMIT {$limit}",
				array_merge([$beforeId], $channelParams),
			);
			$messages = array_reverse($messages);
		} elseif ($sinceId === 0) {
			$messages = $this->connection->fetchAllAssociative(
				"SELECT id, user_id, full_name, message, recipient_id, group_id, attachment_path, attachment_name, attachment_size, attachment_type, created_at
				 FROM admin_chat_message
				 WHERE {$channelWhere}
				 ORDER BY id DESC LIMIT {$limit}",
				$channelParams,
			);
			$messages = array_reverse($messages);
		} else {
			$messages = $this->connection->fetchAllAssociative(
				"SELECT id, user_id, full_name, message, recipient_id, group_id, attachment_path, attachment_name, attachment_size, attachment_type, created_at
				 FROM admin_chat_message
				 WHERE id > ? AND {$channelWhere}
				 ORDER BY id ASC LIMIT {$limit}",
				array_merge([$sinceId], $channelParams),
			);
		}

		// Format
		foreach ($messages as &$m) {
			$m['time'] = (new \DateTime(is_string($m['created_at'] ?? null) ? $m['created_at'] : 'now'))->format('H:i');
			$m['is_mine'] = (intval($m['user_id'] ?? 0) === $userId); // @phpstan-ignore argument.type
			$m['is_dm'] = ($m['recipient_id'] !== null);
		}

		return [
			'messages' => $messages,
			'has_more' => (count($messages) === $limit),
		];
	}


	public function fetchOnlineUsers(int $withinMinutes = 5): array
	{
		$since = (new \DateTime("-{$withinMinutes} minutes"))->format('Y-m-d H:i:s');

		/** @var list<array{user_id: int, full_name: string, email: string}> $result */
		$result = $this->connection->fetchAllAssociative(
			"SELECT DISTINCT user_id, full_name, email
			 FROM user_session
			 WHERE last_heartbeat > ?",
			[$since],
		);

		return $result;
	}


	public function fetchUserGroups(int $userId): array
	{
		/** @var list<array{id: int, name: string}> $result */
		$result = $this->connection->fetchAllAssociative(
			"SELECT g.id, g.name
			 FROM admin_chat_group g
			 JOIN admin_chat_group_member m ON g.id = m.group_id AND m.user_id = ?
			 ORDER BY g.name",
			[$userId],
		);

		return $result;
	}


	public function fetchGroupsWithMembers(int $userId): array
	{
		// Use subquery for member aggregation (works across MySQL, PostgreSQL, SQLite)
		/** @var list<array{id: int, name: string, created_by: int, member_ids: string}> $result */
		$result = $this->connection->fetchAllAssociative(
			"SELECT g.id, g.name, g.created_by,
					(SELECT GROUP_CONCAT(m2.user_id) FROM admin_chat_group_member m2 WHERE m2.group_id = g.id) as member_ids
			 FROM admin_chat_group g
			 JOIN admin_chat_group_member m ON g.id = m.group_id AND m.user_id = ?
			 ORDER BY g.name",
			[$userId],
		);

		return $result;
	}


	/**
	 * @param list<int> $memberIds
	 * @return array{group_id: int, name: string}
	 */
	public function createGroup(string $name, int $creatorId, array $memberIds): array
	{
		$this->connection->executeStatement(
			"INSERT INTO admin_chat_group (name, created_by, created_at) VALUES (?, ?, NOW())",
			[$name, $creatorId],
		);
		$groupId = (int) $this->connection->lastInsertId();

		// Add creator
		$this->connection->executeStatement(
			"INSERT INTO admin_chat_group_member (group_id, user_id) VALUES (?, ?)",
			[$groupId, $creatorId],
		);

		// Add other members (skip duplicates gracefully)
		foreach ($memberIds as $mid) {
			if ($mid === $creatorId) continue;
			try {
				$this->connection->executeStatement(
					"INSERT INTO admin_chat_group_member (group_id, user_id) VALUES (?, ?)",
					[$groupId, $mid],
				);
			} catch (\Throwable) {
				// Duplicate — skip
			}
		}

		return ['group_id' => $groupId, 'name' => $name];
	}


	public function isGroupMember(int $groupId, int $userId): bool
	{
		$count = $this->connection->fetchOne(
			"SELECT COUNT(*) FROM admin_chat_group_member WHERE group_id = ? AND user_id = ?",
			[$groupId, $userId],
		);

		return intval($count) > 0; // @phpstan-ignore argument.type
	}


	public function fetchConversationHistory(int $userId, ?int $groupId = null, int $limit = 10): array
	{
		$limit = (int) $limit; // ensure integer for SQL interpolation

		if ($groupId !== null) {
			$history = $this->connection->fetchAllAssociative(
				"SELECT full_name, message, user_id FROM admin_chat_message
				 WHERE group_id = ? ORDER BY id DESC LIMIT {$limit}",
				[$groupId],
			);
		} else {
			$history = $this->connection->fetchAllAssociative(
				"SELECT full_name, message, user_id FROM admin_chat_message
				 WHERE (user_id = ? AND recipient_id = 0) OR (user_id = 0 AND recipient_id = ?)
				 ORDER BY id DESC LIMIT {$limit}",
				[$userId, $userId],
			);
		}

		/** @var list<array{full_name: string, message: string, user_id: int}> $history */
		return array_reverse($history);
	}


	/**
	 * Build WHERE clause for channel filtering.
	 *
	 * @return array{0: string, 1: list<int>} [whereClause, params]
	 */
	private function buildChannelFilter(int $userId, ?string $channel): array
	{
		if ($channel !== null && str_starts_with($channel, 'g')) {
			$groupId = (int) substr($channel, 1);
			return ["(group_id = ?)", [$groupId]];
		}

		if ($channel !== null && $channel !== '' && $channel !== 'all') {
			$channelId = (int) $channel;
			return [
				"((user_id = ? AND recipient_id = ?) OR (user_id = ? AND recipient_id = ?))",
				[$userId, $channelId, $channelId, $userId],
			];
		}

		return [
			"(group_id IS NULL AND (recipient_id IS NULL OR recipient_id = ? OR user_id = ?))",
			[$userId, $userId],
		];
	}
}
