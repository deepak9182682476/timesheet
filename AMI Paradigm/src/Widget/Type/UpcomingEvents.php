<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Widget\Type;

use App\TeamEvent\TeamEventService;
use App\Widget\WidgetInterface;

/**
 * Dashboard box with the events (team outings, holidays, leave ...) that are
 * running now or coming up for the logged-in person.
 */
final class UpcomingEvents extends AbstractWidget
{
    public function __construct(private readonly TeamEventService $events)
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
        return 'Upcoming events';
    }

    public function getTemplateName(): string
    {
        return 'widget/widget-upcomingevents.html.twig';
    }

    public function getId(): string
    {
        return 'UpcomingEvents';
    }

    /**
     * @param array<string, string|bool|int|null|array<string, mixed>> $options
     */
    public function getData(array $options = []): mixed
    {
        // personal view: the person's own leave and the events of their teams, never a colleague's leave
        return $this->events->getEventsForUser($this->getUser(), new \DateTime('today'), new \DateTime('+90 days'), 8, true);
    }
}
