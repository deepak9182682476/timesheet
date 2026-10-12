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
 * Was the person physically present in the office that day? Entered by an administrator every day
 * (Attendance page, or an Excel upload). One row per person and day.
 */
#[ORM\Table(name: 'kimai2_attendance')]
#[ORM\UniqueConstraint(name: 'UNIQ_ATTENDANCE_USER_DAY', columns: ['user_id', 'day'])]
#[ORM\Entity]
class Attendance
{
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'day', type: Types::DATE_MUTABLE, nullable: false)]
    private ?\DateTime $day = null;

    /** physically present in the office */
    #[ORM\Column(name: 'present', type: Types::BOOLEAN, nullable: false)]
    private bool $present = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: false)]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
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

    public function getDay(): ?\DateTime
    {
        return $this->day;
    }

    public function setDay(?\DateTime $day): void
    {
        $this->day = $day;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function setPresent(bool $present): void
    {
        $this->present = $present;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): void
    {
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTime();
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
}
