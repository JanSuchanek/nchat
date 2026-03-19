<?php

declare(strict_types=1);

namespace NChat\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Chat message entity — supports broadcast, DM, and group messages.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_chat_message')]
#[ORM\Index(columns: ['created_at'], name: 'idx_acm_created')]
#[ORM\Index(columns: ['recipient_id'], name: 'idx_acm_recipient')]
#[ORM\Index(columns: ['group_id'], name: 'idx_acm_group')]
class ChatMessage
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	private ?int $id = null; // @phpstan-ignore property.unusedType

	#[ORM\Column(type: 'integer')]
	private int $userId;

	#[ORM\Column(type: 'string', length: 255)]
	private string $fullName = '';

	#[ORM\Column(type: 'string', length: 255)]
	private string $email = '';

	#[ORM\Column(type: 'text')]
	private string $message = '';

	/** NULL = broadcast, integer = DM to specific user */
	#[ORM\Column(type: 'integer', nullable: true)]
	private ?int $recipientId = null;

	/** NULL = not a group message, integer = group_id */
	#[ORM\Column(type: 'integer', nullable: true)]
	private ?int $groupId = null;

	#[ORM\Column(type: 'datetime_immutable')]
	private \DateTimeImmutable $createdAt;


	public function __construct()
	{
		$this->createdAt = new \DateTimeImmutable();
	}


	public function getId(): ?int { return $this->id; }

	public function setUserId(int $id): self { $this->userId = $id; return $this; }
	public function setFullName(string $n): self { $this->fullName = $n; return $this; }
	public function setEmail(string $e): self { $this->email = $e; return $this; }
	public function setMessage(string $m): self { $this->message = $m; return $this; }
	public function setRecipientId(?int $id): self { $this->recipientId = $id; return $this; }
	public function setGroupId(?int $id): self { $this->groupId = $id; return $this; }

	public function getUserId(): int { return $this->userId; }
	public function getFullName(): string { return $this->fullName; }
	public function getEmail(): string { return $this->email; }
	public function getMessage(): string { return $this->message; }
	public function getRecipientId(): ?int { return $this->recipientId; }
	public function getGroupId(): ?int { return $this->groupId; }
	public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
