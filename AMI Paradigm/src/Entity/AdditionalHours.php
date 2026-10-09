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
 * Additional hours (Apply Leave > Additional Hours): hours worked on a weekend, a festival or holiday, or a night shift.
 * The manager approves them; approved hours become a comp-off credit of a quarter, half or full day (a day is
 * DAY_HOURS hours), which the person can take as "Comp off" leave within CREDIT_VALID_DAYS days of the day worked.
 */
#[ORM\Table(name: 'kimai2_additional_hours')]
#[ORM\Entity]
#[ORM\Index(columns: ['user_id', 'work_date'])]
class AdditionalHours
{
    /** a normal working day */
    public const DAY_HOURS = 9;
    /** a credit has to be used (asked for as comp-off) within this many days of the day worked */
    public const CREDIT_VALID_DAYS = 30;
    /** why the hours were worked => label */
    public const REASONS = ['weekend' => 'Weekend', 'holiday' => 'Festival / holiday', 'night_shift' => 'Night shift'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** the day the hours were worked */
    #[ORM\Column(name: 'work_date', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $workDate = null;

    /** see REASONS */
    #[ORM\Column(name: 'reason', type: Types::STRING, length: 20, nullable: false)]
    private ?string $reason = null;

    #[ORM\Column(name: 'hours', type: Types::FLOAT, nullable: false)]
    private ?float $hours = null;

    /** what the hours are worth: 0.25, 0.5, 0.75 or 1 day */
    #[ORM\Column(name: 'credit_days', type: Types::FLOAT, nullable: false)]
    private float $creditDays = 0.0;

    /** what was done */
    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, nullable: false)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decision_comment', type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    /** false once the manager decided, until the person has looked at it (the bell) */
    #[ORM\Column(name: 'decision_seen', type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $decisionSeen = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * What a number of extra hours is worth, in steps of a quarter day, at most a full day.
     */
    public static function creditFor(float $hours): float
    {
        $quarters = (int) floor(($hours + 0.0001) / (self::DAY_HOURS / 4));

        return min(4, max(0, $quarters)) / 4;
    }

    public static function labelForDays(float $days): string
    {
        return match (true) {
            $days >= 1 => 'Full day',
            $days >= 0.75 => 'Three quarters of a day',
            $days >= 0.5 => 'Half day',
            $days >= 0.25 => 'Quarter day',
            default => 'Nothing',
        };
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

    public function getWorkDate(): ?\DateTime
    {
        return $this->workDate;
    }

    public function setWorkDate(?\DateTime $workDate): void
    {
        $this->workDate = $workDate;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function getReasonLabel(): string
    {
        return self::REASONS[$this->reason ?? ''] ?? '';
    }

    public function getHours(): ?float
    {
        return $this->hours;
    }

    public function setHours(?float $hours): void
    {
        $this->hours = $hours;
        $this->creditDays = self::creditFor((float) $hours);
    }

    public function getCreditDays(): float
    {
        return $this->creditDays;
    }

    public function getCreditLabel(): string
    {
        return self::labelForDays($this->creditDays);
    }

    /** the last day the credit can be used on */
    public function getExpiresOn(): ?\DateTimeImmutable
    {
        return $this->workDate !== null ? \DateTimeImmutable::createFromInterface($this->workDate)->modify('+' . self::CREDIT_VALID_DAYS . ' days') : null;
    }

    public function isExpired(): bool
    {
        return $this->getExpiresOn() !== null && $this->getExpiresOn()->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
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
