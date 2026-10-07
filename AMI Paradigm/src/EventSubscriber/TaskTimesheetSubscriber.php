<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\ActivityTask;
use App\Entity\Task;
use App\Entity\TimesheetMeta;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Task\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Adds a "Task" picker to the time entry form, listing the unfinished tasks
 * of the person the entry belongs to. The hours of entries that pick a task
 * are what the Tasks page shows as "Logged".
 */
final class TaskTimesheetSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TaskService $tasks, private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetMetaDefinitionEvent::class => ['addTaskField', 200],
        ];
    }

    public function addTaskField(TimesheetMetaDefinitionEvent $event): void
    {
        $timesheet = $event->getEntity();
        $user = $timesheet->getUser();
        if ($user === null) {
            return;
        }

        $choices = [];
        // project and activity of each task: the form script only offers the tasks that fit the picked project and activity
        $attributes = [];
        foreach ($this->tasks->getMyTasks($user, false) as $task) {
            $choices[$this->label($task)] = (string) $task->getId();
            $attributes[(string) $task->getId()] = $this->attributes($task);
        }

        // standard tasks of the activities (project > phase > activity > task): open to everybody, stored by name
        foreach ($this->standardTasks() as $standard) {
            $name = (string) $standard->getName();
            if (isset($attributes[$name]) || \in_array($name, $choices, true)) {
                continue;
            }
            $choices[$name] = $name;
            $attributes[$name] = ['data-project' => '', 'data-activity' => (string) $standard->getActivity()?->getId(), 'data-standard' => '1'];
        }

        // an entry that already points to a task keeps it selectable, even once the task is done
        $current = $timesheet->getMetaField(Task::TIMESHEET_META_FIELD);
        $currentValue = $current !== null ? (string) $current->getValue() : '';
        if ($currentValue !== '' && !\in_array($currentValue, $choices, true)) {
            // digits: an assigned task; anything else: the name of a standard task that was renamed or removed since
            $task = ctype_digit($currentValue) ? $this->tasks->find((int) $currentValue) : null;
            $choices[$task !== null ? $this->label($task) : (ctype_digit($currentValue) ? 'Task #' . $currentValue : $currentValue)] = $currentValue;
            $attributes[$currentValue] = $task !== null ? $this->attributes($task) : [];
        }

        // nothing to pick: leave the form as it is
        if ($choices === []) {
            return;
        }

        $definition = new TimesheetMeta();
        $definition->setName(Task::TIMESHEET_META_FIELD);
        $definition->setLabel('Task');
        $definition->setType(ChoiceType::class);
        $definition->setOptions([
            'choices' => $choices,
            'choice_attr' => static fn ($choice, $key, $value) => $attributes[(string) $value] ?? [],
            'attr' => ['class' => 'timesheet-task'],
            'placeholder' => '',
            'translation_domain' => false,
        ]);
        $definition->setIsRequired(false);
        $definition->setIsVisible(true);

        $timesheet->setMetaField($definition);
    }

    /**
     * @return array<ActivityTask>
     */
    private function standardTasks(): array
    {
        try {
            return $this->entityManager->getRepository(ActivityTask::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
        } catch (\Exception) {
            // the table is created by a migration: without it there are simply no standard tasks
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function attributes(Task $task): array
    {
        return [
            'data-project' => (string) $task->getProject()?->getId(),
            'data-activity' => (string) $task->getActivity()?->getId(),
        ];
    }

    private function label(Task $task): string
    {
        return $task->getTitle() . ' (' . $task->getProject()->getName() . ')';
    }
}
