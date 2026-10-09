<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\TimesheetMeta;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Length;

/**
 * "Worked on Leave": time logged on a day of approved leave, as an exception.
 * Nobody approves it: the person ticks "I worked during my leave" on the time entry form and gives a reason.
 * The reason is kept as a custom field of the entry; an entry with a reason is shown with a "Worked on Leave"
 * badge in Log Time and Team Log Time, and the reason goes into the exports, so the lead can review it later.
 * The field only appears on a leave day (partials/leave-day-guard.html.twig); TimesheetApprovedLeaveValidator
 * lets such an entry through only when the reason is filled in.
 */
final class WorkedOnLeaveSubscriber implements EventSubscriberInterface
{
    public const FIELD = 'worked_on_leave';

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetMetaDefinitionEvent::class => ['addToForm', 100],
            TimesheetMetaDisplayEvent::class => ['addToExport', 100],
        ];
    }

    public function addToForm(TimesheetMetaDefinitionEvent $event): void
    {
        $definition = $this->definition();
        $definition->setOptions([
            'label' => 'Reason',
            'translation_domain' => false,
            'required' => false,
            'constraints' => [new Length(max: 255)],
            'attr' => ['maxlength' => 255, 'placeholder' => 'For example: production issue at the client', 'class' => 'worked-on-leave-reason'],
        ]);
        $event->getEntity()->setMetaField($definition);
    }

    /**
     * Not a column of Log Time (a badge shows it there instead), only of the exports.
     */
    public function addToExport(TimesheetMetaDisplayEvent $event): void
    {
        if (\in_array($event->getLocation(), [TimesheetMetaDisplayEvent::EXPORT, TimesheetMetaDisplayEvent::TIMESHEET_EXPORT, TimesheetMetaDisplayEvent::TEAM_TIMESHEET_EXPORT], true)) {
            $event->addField($this->definition());
        }
    }

    private function definition(): TimesheetMeta
    {
        $definition = new TimesheetMeta();
        $definition->setName(self::FIELD);
        $definition->setLabel('Worked on Leave');
        $definition->setType(TextType::class);
        $definition->setIsRequired(false);
        $definition->setIsVisible(true);

        return $definition;
    }
}
