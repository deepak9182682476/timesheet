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
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A phase of work, picked on a time entry between the project and the activity
 * (project > phase > activity > task).
 *
 * A phase either belongs to one project (for example the phases of "Non-Project Activities")
 * or to no project, in which case it is offered for every project that has no phases of its own.
 * The activities linked to a phase are the only ones offered once that phase is picked.
 */
#[ORM\Table(name: 'kimai2_phases')]
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'A phase with this name already exists.')]
class Phase
{
    /** Name of the custom field on a time entry that holds the phase name */
    public const TIMESHEET_META_FIELD = 'phase';
    /** Name of the project every person can book general, non-project work on */
    public const NON_PROJECT_NAME = 'Non-Project Activities';

    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    /** The only project this phase is for; null = every project without phases of its own */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(name: 'position', type: Types::INTEGER, nullable: false)]
    private int $position = 0;

    /** @var Collection<int, Activity> */
    #[ORM\ManyToMany(targetEntity: Activity::class)]
    #[ORM\JoinTable(name: 'kimai2_phase_activities')]
    #[ORM\JoinColumn(name: 'phase_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'activity_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $activities;

    public function __construct()
    {
        $this->activities = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name !== null ? trim($name) : null;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = $position ?? 0;
    }

    /** @return Collection<int, Activity> */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(Activity $activity): void
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
        }
    }

    public function removeActivity(Activity $activity): void
    {
        $this->activities->removeElement($activity);
    }
}
