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
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A standard task of an activity (project > phase > activity > task), for example
 * "Attending Internal Training" under the activity "Technical Training".
 * Unlike an assigned task (Task), it is not given to one person: everybody who picks
 * the activity on a time entry can pick it. Administrators maintain the list on the Phases page.
 */
#[ORM\Table(name: 'kimai2_activity_tasks')]
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'A task with this name already exists.')]
class ActivityTask
{
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 150, nullable: false)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    // a name made of digits only would be mistaken for the number of an assigned task
    #[Assert\Regex(pattern: '/\D/', message: 'The task name needs at least one letter.')]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Activity $activity = null;

    #[ORM\Column(name: 'position', type: Types::INTEGER, nullable: false)]
    private int $position = 0;

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

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function setActivity(?Activity $activity): void
    {
        $this->activity = $activity;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = $position ?? 0;
    }
}
