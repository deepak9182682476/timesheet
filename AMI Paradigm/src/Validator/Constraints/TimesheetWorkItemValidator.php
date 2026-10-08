<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Validator\Constraints;

use App\Entity\Timesheet as TimesheetEntity;
use App\WorkModel\WorkModelService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TimesheetWorkItemValidator extends ConstraintValidator
{
    public function __construct(private readonly WorkModelService $models)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!($constraint instanceof TimesheetWorkItem)) {
            throw new UnexpectedTypeException($constraint, TimesheetWorkItem::class);
        }

        if (!\is_object($value) || !($value instanceof TimesheetEntity)) {
            throw new UnexpectedTypeException($value, TimesheetEntity::class);
        }

        try {
            $problem = $this->models->check($value);
        } catch (\Exception) {
            // the tables are created by a migration: without them nothing is checked
            return;
        }

        if ($problem === null) {
            return;
        }

        $this->context->buildViolation($problem)
            ->atPath('project')
            ->setTranslationDomain('validators')
            ->setCode(TimesheetWorkItem::WORK_ITEM_ERROR)
            ->addViolation();
    }
}
