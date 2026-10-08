<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\Task;
use App\Entity\TimesheetMeta;
use App\Entity\User;
use App\Event\QuickEntryMetaDisplayEvent;
use App\Event\TimesheetCreatePreEvent;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetMetaDisplayEvent;
use App\Event\TimesheetUpdateMultiplePreEvent;
use App\Event\TimesheetUpdatePreEvent;
use App\WorkModel\ExportContext;
use App\WorkModel\WorkModelService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * The item a time entry is booked on (Epic > Feature > User Story > Activity > Task and the like).
 *
 * The entry form carries the number of the picked item in a hidden field; the dropdowns people see are
 * built by the script in partials/work-item-cascade.html.twig. Right before an entry is saved the names
 * of the picked levels are copied onto it, so lists and exports can show them without looking anything up.
 */
final class TimesheetWorkItemSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly WorkModelService $models)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetMetaDefinitionEvent::class => ['addToForm', 500],
            TimesheetMetaDisplayEvent::class => ['addToLists', 350],
            QuickEntryMetaDisplayEvent::class => ['addToWeekGrid', 350],
            TimesheetCreatePreEvent::class => ['onSave', 100],
            TimesheetUpdatePreEvent::class => ['onSave', 100],
            TimesheetUpdateMultiplePreEvent::class => ['onSaveMultiple', 100],
        ];
    }

    public function addToForm(TimesheetMetaDefinitionEvent $event): void
    {
        $timesheet = $event->getEntity();

        // all hidden: the item is set by the form script, the names by onSave()
        foreach (array_merge([WorkModelService::META_ITEM => 'Item'], WorkModelService::META_LEVELS) as $name => $label) {
            $definition = new TimesheetMeta();
            $definition->setName($name);
            $definition->setLabel($label);
            $definition->setType(HiddenType::class);
            $definition->setIsRequired(false);
            $definition->setIsVisible($name !== WorkModelService::META_ITEM);
            $timesheet->setMetaField($definition);
        }
    }

    public function addToLists(TimesheetMetaDisplayEvent $event): void
    {
        // Exports: the columns of the kind of work that is exported, named the way that model names its levels.
        // The Task comes last here; the export puts it behind the activity (see Export/DefaultTemplate).
        if ($event->getLocation() === TimesheetMetaDisplayEvent::EXPORT) {
            foreach (ExportContext::get()['columns'] as $name => $label) {
                $event->addField($this->column($name, $label));
            }
            $event->addField($this->column(Task::TIMESHEET_META_FIELD, 'Task'));

            return;
        }

        foreach (WorkModelService::META_LEVELS as $name => $label) {
            $event->addField($this->column($name, $label));
        }
        $event->addField($this->column(Task::TIMESHEET_META_FIELD, 'Task'));
    }

    /**
     * "Weekly hours": one more column per row to pick the item, with the whole path in one dropdown
     * (Epic > Feature > User Story > Activity > Task). The script in partials/work-item-cascade.html.twig
     * narrows it to the project of the row and sets the activity that belongs to the picked item.
     */
    public function addToWeekGrid(QuickEntryMetaDisplayEvent $event): void
    {
        $user = $event->getQuery()->getUser();
        if (!($user instanceof User)) {
            return;
        }

        try {
            $items = $this->models->getBookableItems($user);
        } catch (\Exception) {
            // the tables are created by a migration: without them the grid stays as it was
            return;
        }

        $choices = [];
        $attributes = [];
        foreach ($items as $item) {
            $label = $item['label'];
            // two items can share a name: the dropdown needs every label once
            while (isset($choices[$label])) {
                $label .= ' ';
            }
            $choices[$label] = (string) $item['id'];
            $attributes[(string) $item['id']] = [
                'data-project' => (string) $item['project'],
                'data-activity' => (string) $item['activity'],
                'data-activity-name' => $item['activityName'],
            ];
        }

        $definition = new TimesheetMeta();
        $definition->setName(WorkModelService::META_ITEM);
        $definition->setLabel('Item');
        $definition->setType(ChoiceType::class);
        $definition->setOptions([
            'choices' => $choices,
            'choice_attr' => static fn ($choice, $key, $value) => $attributes[(string) $value] ?? [],
            'placeholder' => '',
            'translation_domain' => false,
            'attr' => ['class' => 'wi-grid-item', 'style' => 'min-width: 200px'],
        ]);
        $definition->setIsRequired(false);
        $definition->setIsVisible(true);
        $event->addField($definition);
    }

    public function onSave(TimesheetCreatePreEvent|TimesheetUpdatePreEvent $event): void
    {
        $this->models->apply($event->getTimesheet());
    }

    public function onSaveMultiple(TimesheetUpdateMultiplePreEvent $event): void
    {
        foreach ($event->getTimesheets() as $timesheet) {
            $this->models->apply($timesheet);
        }
    }

    private function column(string $name, string $label): TimesheetMeta
    {
        $definition = new TimesheetMeta();
        $definition->setName($name);
        $definition->setLabel($label);
        $definition->setType(TextType::class);
        $definition->setIsRequired(false);
        $definition->setIsVisible(true);

        return $definition;
    }
}
