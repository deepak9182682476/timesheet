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
 * A day (or several) that is not ordinary work: a team outing, a holiday, leave, training.
 * It applies to one person, to one team, or - when neither is set - to everyone.
 * Events are shown on the dashboard; they do not add worked hours.
 */
#[ORM\Table(name: 'kimai2_team_events')]
#[ORM\Entity]
#[ORM\Index(columns: ['start_date', 'end_date'])]
class TeamEvent
{
    public const TYPE_OUTING = 'team_outing';
    public const TYPE_HOLIDAY = 'holiday';
    public const TYPE_LEAVE = 'leave';
    public const TYPE_TRAINING = 'training';
    public const TYPE_OTHER = 'other';

    /** type => label shown to people */
    public const TYPES = [
        self::TYPE_OUTING => 'Team outing',
        self::TYPE_HOLIDAY => 'Holiday',
        self::TYPE_LEAVE => 'Leave',
        self::TYPE_TRAINING => 'Training',
        self::TYPE_OTHER => 'Other',
    ];

    /**
     * Two kinds of event:
     * - activity: something people spend time on (team lunch, training). Its hours are logged automatically in the
     *   timesheet of everybody it applies to (TeamEventTimesheetSync), so it shows in Log Time and the bar graph.
     * - information: something to know (the head visits, come in formals). Shown in Notifications and on that day
     *   in Bulk Entry (Week); nothing is logged.
     */
    public const KIND_ACTIVITY = 'activity';
    public const KIND_INFORMATION = 'information';
    public const KINDS = [self::KIND_ACTIVITY => 'Activity', self::KIND_INFORMATION => 'Information'];

    /** Leave an employee applied for waits as "pending" until a manager decides; everything else is "approved" */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Types offered when adding an event. Leave has its own page (Apply leave) and holidays come from the
     * holiday calendar, so neither is offered here; both stay in TYPES for the entries that already exist.
     */
    public const EVENT_FORM_TYPES = [self::TYPE_OUTING, self::TYPE_TRAINING, self::TYPE_OTHER];

    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'title', type: Types::STRING, length: 150, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 150, normalizer: 'trim')]
    private ?string $title = null;

    #[ORM\Column(name: 'type', type: Types::STRING, length: 20, nullable: false)]
    #[Assert\Choice(callback: 'getTypeKeys')]
    private string $type = self::TYPE_OUTING;

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'start_date', type: Types::DATE_MUTABLE, nullable: false)]
    #[Assert\NotNull]
    private ?\DateTime $startDate = null;

    /** Last day of the event; the same as the start date for a single day */
    #[ORM\Column(name: 'end_date', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $endDate = null;

    /** Optional time of day the event starts (on the first day) */
    #[ORM\Column(name: 'start_time', type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTime $startTime = null;

    /** Optional time of day the event ends (on the last day) */
    #[ORM\Column(name: 'end_time', type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTime $endTime = null;

    /** Set when the event is for one person only */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Set when the event is for one team */
    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: true, onDelete: 'CASCADE')]
    private ?Team $team = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $createdAt;

    /** Only approved events are shown on dashboards */
    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, nullable: false, options: ['default' => 'approved'])]
    private string $status = self::STATUS_APPROVED;

    /** The manager who approved or rejected a leave request */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /** Optional: why the manager rejected the leave request */
    #[ORM\Column(name: 'decision_comment', type: Types::TEXT, nullable: true)]
    private ?string $decisionComment = null;

    /** True once the days of this approved leave were put into the person's timesheet */
    #[ORM\Column(name: 'timesheet_synced', type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $timesheetSynced = false;

    /** The leave type of a comp-off: it uses a credit from approved additional hours */
    // public const COMP_OFF = 'Comp off';
    public const COMP_OFF = 'Comp-off Leave';

    /** Comp-off: the approved additional hours it is taken for */
    #[ORM\ManyToOne(targetEntity: AdditionalHours::class)]
    #[ORM\JoinColumn(name: 'comp_credit_id', nullable: true, onDelete: 'SET NULL')]
    private ?AdditionalHours $compCredit = null;

    /** False once a manager decided on the person's leave, until the person has looked at it (the bell) */
    #[ORM\Column(name: 'decision_seen', type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $decisionSeen = true;

    public function isCompOff(): bool
    {
        return $this->type === self::TYPE_LEAVE && $this->title === self::COMP_OFF;
    }

    /**
     * A comp-off worth less than a full day (a quarter, half or three quarters): the rest of that day
     * is a normal working day, so time can be logged on it.
     */
    public function isPartDay(): bool
    {
        return $this->isCompOff() && $this->compCredit !== null && $this->compCredit->getCreditDays() < 1;
    }

    public function getCompCredit(): ?AdditionalHours
    {
        return $this->compCredit;
    }

    public function setCompCredit(?AdditionalHours $credit): void
    {
        $this->compCredit = $credit;
    }

    public function isDecisionSeen(): bool
    {
        return $this->decisionSeen;
    }

    public function setDecisionSeen(bool $seen): void
    {
        $this->decisionSeen = $seen;
    }

    /** Activity (logged in the timesheets) or information (only shown), see KINDS */
    #[ORM\Column(name: 'event_kind', type: Types::STRING, length: 20, nullable: false, options: ['default' => 'information'])]
    private string $kind = self::KIND_INFORMATION;

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(?string $kind): void
    {
        $this->kind = $kind === self::KIND_ACTIVITY ? self::KIND_ACTIVITY : self::KIND_INFORMATION;
    }

    public function isActivity(): bool
    {
        return $this->kind === self::KIND_ACTIVITY;
    }

    public function getKindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Information';
    }

    /** True for an event that is for every team of the person who added it ("All my teams") */
    #[ORM\Column(name: 'all_my_teams', type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $allMyTeams = false;

    public function isAllMyTeams(): bool
    {
        return $this->allMyTeams;
    }

    public function setAllMyTeams(bool $allMyTeams): void
    {
        $this->allMyTeams = $allMyTeams;
    }

    public function isTimesheetSynced(): bool
    {
        return $this->timesheetSynced;
    }

    public function setTimesheetSynced(bool $timesheetSynced): void
    {
        $this->timesheetSynced = $timesheetSynced;
    }

    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    public function setDecisionComment(?string $decisionComment): void
    {
        $decisionComment = $decisionComment !== null ? trim($decisionComment) : null;
        $this->decisionComment = ($decisionComment === null || $decisionComment === '') ? null : mb_substr($decisionComment, 0, 1000);
    }

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * @return array<string>
     */
    public static function getTypeKeys(): array
    {
        return array_keys(self::TYPES);
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
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
        return $this->endDate ?? $this->startDate;
    }

    public function setEndDate(?\DateTime $endDate): void
    {
        $this->endDate = $endDate;
    }

    /**
     * A single-day event has no separate last day: store the first day as the last day too.
     *
     * Needed because getEndDate() already answers with the first day when no last day is stored,
     * so a form that receives "last day = first day" sees no change and never calls setEndDate().
     */
    public function fillMissingEndDate(): void
    {
        if ($this->endDate === null && $this->startDate !== null) {
            $this->endDate = clone $this->startDate;
        }
    }

    public function getStartTime(): ?\DateTime
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTime $startTime): void
    {
        $this->startTime = $startTime;
    }

    public function getEndTime(): ?\DateTime
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTime $endTime): void
    {
        $this->endTime = $endTime;
    }

    public function hasTime(): bool
    {
        return $this->startTime !== null || $this->endTime !== null;
    }

    public function isMultiDay(): bool
    {
        return $this->startDate !== null && $this->getEndDate() !== null
            && $this->startDate->format('Y-m-d') !== $this->getEndDate()->format('Y-m-d');
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): void
    {
        $this->team = $team;
    }

    /**
     * Who the event is for, in words.
     */
    public function getAudienceLabel(): string
    {
        if ($this->user !== null) {
            return $this->user->getDisplayName();
        }

        if ($this->team !== null) {
            return 'Team ' . $this->team->getName();
        }

        if ($this->allMyTeams) {
            $names = [];
            foreach ($this->createdBy?->getTeams() ?? [] as $team) {
                $names[] = (string) $team->getName();
            }
            sort($names);

            return $names !== [] ? 'Teams ' . implode(', ', $names) : 'All teams of ' . $this->createdBy?->getDisplayName();
        }

        return 'Everyone';
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!\in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('Unknown status: ' . $status);
        }

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

    /**
     * Number of calendar days from the first to the last day, both included.
     */
    public function getDays(): int
    {
        if ($this->startDate === null || $this->getEndDate() === null) {
            return 0;
        }

        return (int) $this->startDate->diff($this->getEndDate())->days + 1;
    }

    /**
     * Working days (Monday to Friday) from the first to the last day, both included.
     * This is what a leave costs: weekends inside the range are not counted.
     */
    public function getWorkingDays(): int
    {
        if ($this->startDate === null || $this->getEndDate() === null) {
            return 0;
        }

        $days = 0;
        $day = clone $this->startDate;
        $last = $this->getEndDate()->format('Y-m-d');
        $guard = 0;
        while ($day->format('Y-m-d') <= $last && $guard++ < 800) {
            if ((int) $day->format('N') <= 5) {
                $days++;
            }
            $day->modify('+1 day');
        }

        return $days;
    }

    /**
     * True for leave a person applied for themselves (as opposed to an event a manager added).
     */
    public function isLeaveRequest(): bool
    {
        return $this->type === self::TYPE_LEAVE && $this->user !== null && $this->createdBy !== null
            && $this->user->getId() === $this->createdBy->getId();
    }
}
