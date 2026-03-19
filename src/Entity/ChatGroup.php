<?php

declare(strict_types=1);

namespace NChat\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Chat group entity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_chat_group')]
class ChatGroup
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	private ?int $id = null; // @phpstan-ignore property.unusedType

	#[ORM\Column(type: 'string', length: 255)]
	private string $name = '';

	#[ORM\Column(type: 'integer')]
	private int $createdBy;

	#[ORM\Column(type: 'datetime_immutable')]
	private \DateTimeImmutable $createdAt;


	public function __construct()
	{
		$this->createdAt = new \DateTimeImmutable();
	}


	public function getId(): ?int { return $this->id; }

	public function setName(string $name): self { $this->name = $name; return $this; }
	public function setCreatedBy(int $userId): self { $this->createdBy = $userId; return $this; }

	public function getName(): string { return $this->name; }
	public function getCreatedBy(): int { return $this->createdBy; }
	public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
