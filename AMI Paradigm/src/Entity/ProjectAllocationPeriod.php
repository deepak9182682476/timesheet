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
 * One time a person works on a project: Allocation No. (1, 2 ...) of their allocation, from and to.
 */
#[ORM\Table(name: 'kimai2_project_allocation_periods')]
#[ORM\UniqueConstraint(name: 'UNIQ_ALLOC_PERIOD_NUMBER', columns: ['allocation_id', 'number'])]
#[ORM\Entity]
class ProjectAllocationPeriod
{
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProjectAllocation::class)]
    #[ORM\JoinColumn(name: 'allocation_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProjectAllocation $allocation = null;

    /** Allocation No. */
    #[ORM\Column(name: 'number', type: Types::INTEGER, nullable: false)]
    private int $number = 1;

    #[ORM\Column(name: 'start_date', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $endDate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private ?\DateTime $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAllocation(): ?ProjectAllocation
    {
        return $this->allocation;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStartDate(): ?\DateTime
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTime
    {
        return $this->endDate;
    }
}
