<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A request to work from home (Apply Leave > WFH Request) for one day or a few days. The person's immediate
 * supervisor approves it. On those days the time entries count as worked even when the attendance (see
 * Attendance) says the person was not in the office.
 */
#[ORM\Table(name: 'kimai2_wfh_requests')]
#[ORM\Entity]
#[ORM\Index(columns: ['user_id', 'start_date'])]
class WfhRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    /** a request can be made this many days back (for a day already worked from home) */
    public const DAYS_BACK = 30;

    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'start_date', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $startDate = null;

    /** the last day; the same as the first for a single day */
    #[ORM\Column(name: 'end_date', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $endDate = null;

    #[ORM\Column(name: 'reason', type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, nullable: false)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decision_comment', type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    /** false once the supervisor decided, until the person has looked at it (the bell) */
    #[ORM\Column(name: 'decision_seen', type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $decisionSeen = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getStartDate(): ?\DateTime
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTime $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function getEndDate(): ?\DateTime
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTime $endDate): void
    {
        $this->endDate = $endDate;
    }

    public function isMultiDay(): bool
    {
        return $this->startDate !== null && $this->endDate !== null && $this->startDate->format('Y-m-d') !== $this->endDate->format('Y-m-d');
    }

    /** the number of days, weekends included */
    public function getDays(): int
    {
        if ($this->startDate === null || $this->endDate === null) {
            return 0;
        }

        return (int) $this->startDate->diff($this->endDate)->days + 1;
    }

    public function covers(string $day): bool
    {
        return $this->startDate !== null && $this->endDate !== null && $day >= $this->startDate->format('Y-m-d') && $day <= $this->endDate->format('Y-m-d');
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): void
    {
        $this->decidedBy = $decidedBy;
    }

    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    public function setDecisionComment(?string $decisionComment): void
    {
        $this->decisionComment = $decisionComment;
    }

    public function isDecisionSeen(): bool
    {
        return $this->decisionSeen;
    }

    public function setDecisionSeen(bool $seen): void
    {
        $this->decisionSeen = $seen;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }
}
