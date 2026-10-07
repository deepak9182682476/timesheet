<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Widget\Type;

use App\Task\TaskService;
use App\Widget\WidgetInterface;

/**
 * Dashboard box listing the unfinished tasks assigned to the logged-in person.
 */
final class MyTasks extends AbstractWidget
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function getWidth(): int
    {
        return WidgetInterface::WIDTH_HALF;
    }

    public function getHeight(): int
    {
        return WidgetInterface::HEIGHT_LARGE;
    }

    public function getTitle(): string
    {
        return 'My tasks';
    }

    public function getTemplateName(): string
    {
        return 'widget/widget-mytasks.html.twig';
    }

    public function getId(): string
    {
        return 'MyTasks';
    }

    /**
     * @param array<string, string|bool|int|null|array<string, mixed>> $options
     */
    public function getData(array $options = []): mixed
    {
        $tasks = $this->tasks->getMyTasks($this->getUser(), false);

        return [
            'tasks' => $tasks,
            'logged' => $this->tasks->getLoggedSeconds($tasks),
        ];
    }
}
