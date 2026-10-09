<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use App\Entity\TeamEvent;
use App\Entity\Timesheet as TimesheetEntity;
use App\Entity\User;
use App\TeamEvent\LeaveTimesheetSync;
use App\TeamEvent\TeamEventService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TimesheetApprovedLeaveValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamEventService $events,
        private readonly Security $security,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!($constraint instanceof TimesheetApprovedLeave)) {
            throw new UnexpectedTypeException($constraint, TimesheetApprovedLeave::class);
        }

        if (!\is_object($value) || !($value instanceof TimesheetEntity)) {
            throw new UnexpectedTypeException($value, TimesheetEntity::class);
        }

        $user = $value->getUser();
        $begin = $value->getBegin();
        // only new entries are stopped: what is in the timesheet already can still be corrected.
        // The entries the leave itself created carry the leave's number and are never stopped.
        if ($user === null || $begin === null || $value->getId() !== null || $value->getMetaField(LeaveTimesheetSync::META_FIELD) !== null) {
            return;
        }

        // the exception: "I worked during my leave" with a reason (WorkedOnLeaveSubscriber) is saved, and marked
        $reason = $value->getMetaField(\App\EventSubscriber\WorkedOnLeaveSubscriber::FIELD);
        if ($reason !== null && trim((string) $reason->getValue()) !== '') {
            return;
        }

        $day = (clone $begin)->setTimezone(new \DateTimeZone($user->getTimezone()))->format('Y-m-d');

        try {
            $leaves = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from(TeamEvent::class, 'e')
                ->where('e.user = :user')
                ->andWhere('e.type = :type')
                ->andWhere('e.status = :status')
                ->andWhere('e.startDate <= :day')
                ->andWhere('e.endDate >= :day')
                ->setParameter('user', $user)
                ->setParameter('type', TeamEvent::TYPE_LEAVE)
                ->setParameter('status', TeamEvent::STATUS_APPROVED)
                ->setParameter('day', $day)
                ->getQuery()
                ->getResult();
        } catch (\Exception) {
            return;
        }

        foreach ($leaves as $leave) {
            // a weekend or holiday inside a longer leave is not a leave day: working then stays possible
            if (!\in_array($day, $this->events->getLeaveDates($leave), true)) {
                continue;
            }

            $current = $this->security->getUser();
            $own = $current instanceof User && $current->getId() === $user->getId();

            $this->context->buildViolation($own ? $constraint->message : $constraint->messageOther)
                ->setParameter('{{ date }}', (new \DateTimeImmutable($day))->format('d-M-y'))
                ->setParameter('{{ leave }}', (string) $leave->getTitle())
                ->setParameter('{{ name }}', $user->getDisplayName())
                ->atPath('begin_date')
                ->setCode(TimesheetApprovedLeave::APPROVED_LEAVE_ERROR)
                ->addViolation();

            return;
        }
    }
}
