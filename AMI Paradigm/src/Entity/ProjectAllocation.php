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
 * A person allocated to a project (Team Allocation), with its own Allocation ID (for example ALC-0007).
 * The times the person works on the project are its periods: Allocation No. 1, 2 ... (ProjectAllocationPeriod).
 */
#[ORM\Table(name: 'kimai2_project_allocations')]
#[ORM\UniqueConstraint(name: 'UNIQ_ALLOC_CODE', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'UNIQ_ALLOC_PROJECT_USER', columns: ['project_id', 'user_id'])]
#[ORM\Entity]
class ProjectAllocation
{
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    /** the Allocation ID, unique */
    #[ORM\Column(name: 'code', type: Types::STRING, length: 20, nullable: false)]
    private string $code = '';

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }
}
