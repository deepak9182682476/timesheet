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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Adds a "Status" field (In progress / Completed) to every time entry.
 * It is stored as a custom field of the entry, shown in the entry form below the
 * activity, and available as a column in the timesheet lists and exports.
 */
final class TimesheetStatusSubscriber implements EventSubscriberInterface
{
    /** Name of the custom field on a time entry */
    public const FIELD = 'status';

    public const IN_PROGRESS = 'In progress';
    public const COMPLETED = 'Completed';

    public static function getSubscribedEvents(): array
    {
        return [
            // before the task picker (priority 200), so Status comes first among the custom fields
            TimesheetMetaDefinitionEvent::class => ['addToForm', 300],
            TimesheetMetaDisplayEvent::class => ['addToLists', 300],
        ];
    }

    public function addToForm(TimesheetMetaDefinitionEvent $event): void
    {
        $event->getEntity()->setMetaField($this->definition());
    }

    public function addToLists(TimesheetMetaDisplayEvent $event): void
    {
        $event->addField($this->definition());
    }

    private function definition(): TimesheetMeta
    {
        $definition = new TimesheetMeta();
        $definition->setName(self::FIELD);
        $definition->setLabel('Status');
        $definition->setType(ChoiceType::class);
        $definition->setOptions([
            'choices' => [
                self::IN_PROGRESS => self::IN_PROGRESS,
                self::COMPLETED => self::COMPLETED,
            ],
            'translation_domain' => false,
        ]);
        // a new entry starts as "In progress"; an existing entry keeps the status it has
        $definition->setValue(self::IN_PROGRESS);
        $definition->setIsRequired(true);
        $definition->setIsVisible(true);

        return $definition;
    }
}
