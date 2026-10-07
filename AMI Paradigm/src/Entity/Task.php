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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A piece of work that a manager or lead assigns to one person.
 */
#[ORM\Table(name: 'kimai2_tasks')]
#[ORM\Entity]
#[ORM\Index(columns: ['status'])]
class Task
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_DONE];

    /** Name of the custom field on a time entry that points to a task */
    public const TIMESHEET_META_FIELD = 'task';

    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'title', type: Types::STRING, length: 200, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 200, normalizer: 'trim')]
    private ?string $title = null;

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Project $project = null;

    /** False until the assignee has opened the Tasks page: such tasks are listed in their notification bell */
    #[ORM\Column(name: 'assignee_seen', type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $assigneeSeen = false;

    /** Optional: the activity this task belongs to; the task is then only offered on entries with that activity */
    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    /** The person who has to do the work */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assignee_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $assignee = null;

    /** The manager or lead who assigned it */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'due_date', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dueDate = null;

    /** Estimated effort in seconds */
    #[ORM\Column(name: 'estimate', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $estimate = null;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, nullable: false)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $createdAt;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $completedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function isAssigneeSeen(): bool
    {
        return $this->assigneeSeen;
    }

    public function setAssigneeSeen(bool $assigneeSeen): void
    {
        $this->assigneeSeen = $assigneeSeen;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function setActivity(?Activity $activity): void
    {
        $this->activity = $activity;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): void
    {
        $this->assignee = $assignee;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getDueDate(): ?\DateTime
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTime $dueDate): void
    {
        $this->dueDate = $dueDate;
    }

    public function getEstimate(): ?int
    {
        return $this->estimate;
    }

    public function setEstimate(?int $estimate): void
    {
        $this->estimate = $estimate;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown task status: ' . $status);
        }

        if ($status === self::STATUS_DONE && $this->status !== self::STATUS_DONE) {
            $this->completedAt = new \DateTime();
        } elseif ($status !== self::STATUS_DONE) {
            $this->completedAt = null;
        }

        $this->status = $status;
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * Open or in progress, and the due date has passed.
     */
    public function isOverdue(): bool
    {
        if ($this->dueDate === null || $this->isDone()) {
            return false;
        }

        return $this->dueDate->format('Y-m-d') < (new \DateTime())->format('Y-m-d');
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTime
    {
        return $this->completedAt;
    }
}
