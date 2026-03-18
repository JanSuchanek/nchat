<?php

declare(strict_types=1);

namespace NChat\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Chat group membership entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_chat_group_member')]
class ChatGroupMember
{
	#[ORM\Id]
	#[ORM\Column(type: 'integer')]
	private int $groupId;

	#[ORM\Id]
	#[ORM\Column(type: 'integer')]
	private int $userId;


	public function __construct(int $groupId, int $userId)
	{
		$this->groupId = $groupId;
		$this->userId = $userId;
	}


	public function getGroupId(): int { return $this->groupId; }
	public function getUserId(): int { return $this->userId; }
}
