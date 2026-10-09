<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One item of a project's breakdown that people book time on.
 *
 * What an item is called depends on the model of its project and on how deep it sits
 * (see WorkModelService::LEVELS): in an Agile project the levels are
 * Epic > Feature > User Story > Activity > Task, in a Waterfall project
 * Module > Sub Module > Business Req > Activity > Task, and in Pre-sales Lead > Phase > Activity > Task.
 *
 * Managers and leads create the items and assign them to people. An item without people of its own
 * is for the same people as the item above it.
 */
#[ORM\Table(name: 'kimai2_work_items')]
#[ORM\Entity]
class WorkItem
{
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: WorkItem::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?WorkItem $parent = null;

    /** 0 for the top level (Epic, Module, Lead), counting up from there */
    #[ORM\Column(name: 'level', type: Types::INTEGER, nullable: false)]
    private int $level = 0;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 150, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    #[Assert\Regex(pattern: '/^\d+$/', match: false, message: 'Please use a name, not just a number.')]
    private ?string $name = null;

    /** Only for an item on the "Activity" level: the activity the time entries are saved with */
    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'position', type: Types::INTEGER, nullable: false)]
    private int $position = 0;

    /** How long it should take, as the manager or lead writes it (free text, for example "16 hours" or "2 days") */
    #[ORM\Column(name: 'estimate', type: Types::STRING, length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $estimate = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'kimai2_work_item_users')]
    #[ORM\JoinColumn(name: 'work_item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getParent(): ?WorkItem
    {
        return $this->parent;
    }

    public function setParent(?WorkItem $parent): void
    {
        $this->parent = $parent;
        $this->level = $parent !== null ? $parent->getLevel() + 1 : 0;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name !== null ? trim($name) : null;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function setActivity(?Activity $activity): void
    {
        $this->activity = $activity;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getEstimate(): ?string
    {
        return $this->estimate;
    }

    public function setEstimate(?string $estimate): void
    {
        $estimate = $estimate !== null ? trim($estimate) : null;
        $this->estimate = $estimate === '' ? null : mb_substr($estimate, 0, 100);
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = $position ?? 0;
    }

    /** @return Collection<int, User> the people this item was assigned to directly (empty: same as the item above) */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): void
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }
    }

    public function removeUser(User $user): void
    {
        $this->users->removeElement($user);
    }

    /**
     * The people who can book time on this item: its own people, or else the people of the nearest item above that has any.
     *
     * @return array<int, User> keyed by user ID
     */
    public function getEffectiveUsers(): array
    {
        $item = $this;
        $guard = 0;
        while ($item !== null && $guard++ < 20) {
            if (!$item->getUsers()->isEmpty()) {
                $users = [];
                foreach ($item->getUsers() as $user) {
                    $users[(int) $user->getId()] = $user;
                }

                return $users;
            }
            $item = $item->getParent();
        }

        return [];
    }

    /**
     * This item and the items above it, top level first.
     *
     * @return array<int, WorkItem> keyed by level
     */
    public function getPath(): array
    {
        $path = [];
        $item = $this;
        $guard = 0;
        while ($item !== null && $guard++ < 20) {
            $path[$item->getLevel()] = $item;
            $item = $item->getParent();
        }
        ksort($path);

        return $path;
    }
}
