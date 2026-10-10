<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use App\Entity\Timesheet as TimesheetEntity;
use App\Entity\User;
use App\Timesheet\WeeklyCutoffService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TimesheetWeeklyCutoffValidator extends ConstraintValidator
{
    public function __construct(
        private readonly WeeklyCutoffService $cutoff,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!($constraint instanceof TimesheetWeeklyCutoff)) {
            throw new UnexpectedTypeException($constraint, TimesheetWeeklyCutoff::class);
        }
        if (!($value instanceof TimesheetEntity)) {
            throw new UnexpectedTypeException($value, TimesheetEntity::class);
        }

        // "For Multiple Users": the form itself stands for nobody; every person's copy is checked on its own
        // (TimesheetTeamController::createForMultiUserAction validates each one before anything is saved)
        if ($value instanceof \App\Form\Model\MultiUserTimesheet) {
            return;
        }

        // only what a person does: the system (leave, team events, the console) is not limited
        $actor = $this->security->getUser();
        if (!($actor instanceof User) || $value->getBegin() === null) {
            return;
        }
        $owner = $value->getUser() ?? $actor;

        // the new date, and for a change also the date the entry had before (an old entry cannot be moved out of a closed week)
        $days = [$value->getBegin()];
        if ($value->getId() !== null) {
            $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($value);
            if (($original['begin'] ?? null) instanceof \DateTimeInterface) {
                $days[] = $original['begin'];
            }
        }

        foreach ($days as $day) {
            $message = $this->cutoff->getMessage($actor, $owner, $day);
            if ($message !== null) {
                $this->context->buildViolation($message)
                    ->atPath('begin_date')
                    ->setCode(TimesheetWeeklyCutoff::CUTOFF_ERROR)
                    ->addViolation();

                return;
            }
        }
    }
}
