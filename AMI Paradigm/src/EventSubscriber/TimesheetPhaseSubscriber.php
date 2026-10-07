<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\Phase;
use App\Entity\TimesheetMeta;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Adds the "Phase" picker to every time entry (project > phase > activity > task).
 * The phase name is stored as a custom field of the entry. Each option carries the project it
 * is for and the activities linked to it; the script in partials/timesheet-cascade.html.twig
 * uses that to narrow the phases to the picked project and the activities to the picked phase.
 */
final class TimesheetPhaseSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // before Status (300) and Task (200)
            TimesheetMetaDefinitionEvent::class => ['addToForm', 400],
            TimesheetMetaDisplayEvent::class => ['addToLists', 400],
        ];
    }

    public function addToForm(TimesheetMetaDefinitionEvent $event): void
    {
        $timesheet = $event->getEntity();

        $choices = [];
        $attributes = [];
        try {
            $phases = $this->entityManager->getRepository(Phase::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
        } catch (\Exception) {
            // the phases table is created by a migration: without it the form simply has no phase
            return;
        }
        foreach ($phases as $phase) {
            $name = (string) $phase->getName();
            $choices[$name] = $name;
            $activityIds = [];
            foreach ($phase->getActivities() as $activity) {
                $activityIds[] = $activity->getId();
            }
            $attributes[$name] = [
                'data-project' => $phase->getProject() !== null ? (string) $phase->getProject()->getId() : '',
                'data-activities' => implode(',', $activityIds),
            ];
        }

        // an entry keeps the phase it was saved with, even if that phase was renamed or deleted since
        $current = $timesheet->getMetaField(Phase::TIMESHEET_META_FIELD);
        $currentValue = $current !== null ? (string) $current->getValue() : '';
        if ($currentValue !== '' && !isset($choices[$currentValue])) {
            $choices[$currentValue] = $currentValue;
            $attributes[$currentValue] = ['data-project' => '*', 'data-activities' => ''];
        }

        if ($choices === []) {
            return;
        }

        $definition = $this->definition();
        $definition->setOptions([
            'choices' => $choices,
            'choice_attr' => static fn ($choice, $key, $value) => $attributes[(string) $value] ?? [],
            'placeholder' => '',
            'translation_domain' => false,
            'attr' => ['class' => 'timesheet-phase'],
        ]);
        $timesheet->setMetaField($definition);
    }

    public function addToLists(TimesheetMetaDisplayEvent $event): void
    {
        $event->addField($this->definition());
    }

    private function definition(): TimesheetMeta
    {
        $definition = new TimesheetMeta();
        $definition->setName(Phase::TIMESHEET_META_FIELD);
        $definition->setLabel('Phase');
        $definition->setType(ChoiceType::class);
        $definition->setIsRequired(false);
        $definition->setIsVisible(true);

        return $definition;
    }
}
