<?php

declare(strict_types=1);

namespace NChat\Storage;

/**
 * Database abstraction for NChat.
 *
 * Implement this interface to use any database (PostgreSQL, MySQL, SQLite, etc.).
 * All methods work with plain arrays — no ORM dependency required.
 */
interface ChatStorageInterface
{
	/**
	 * Save a chat message.
	 *
	 * @param array{user_id: int, full_name: string, email: string, message: string, recipient_id: ?int, group_id: ?int} $data
	 * @return int Inserted message ID
	 */
	public function saveMessage(array $data): int;


	/**
	 * Fetch messages with filtering and pagination.
	 *
	 * @param int $userId Current user ID (for DM filtering)
	 * @param array{since_id?: int, before_id?: int, channel?: ?string, limit?: int} $criteria
	 * @return array{messages: list<array<string, mixed>>, has_more: bool}
	 */
	public function fetchMessages(int $userId, array $criteria = []): array;


	/**
	 * Fetch online users (active within last N minutes).
	 *
	 * @return list<array{user_id: int, full_name: string, email: string}>
	 */
	public function fetchOnlineUsers(int $withinMinutes = 5): array;


	/**
	 * Fetch groups the user belongs to.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public function fetchUserGroups(int $userId): array;


	/**
	 * Fetch groups with member IDs.
	 *
	 * @return list<array{id: int, name: string, created_by: int, member_ids: string}>
	 */
	public function fetchGroupsWithMembers(int $userId): array;


	/**
	 * Create a group and add members.
	 *
	 * @param list<int> $memberIds
	 * @return array{group_id: int, name: string}
	 */
	public function createGroup(string $name, int $creatorId, array $memberIds): array;


	/**
	 * Check if a user is a member of a group.
	 */
	public function isGroupMember(int $groupId, int $userId): bool;


	/**
	 * Fetch conversation history for AI context.
	 *
	 * @return list<array{full_name: string, message: string, user_id: int}>
	 */
	public function fetchConversationHistory(int $userId, ?int $groupId = null, int $limit = 10): array;
}
