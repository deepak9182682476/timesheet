<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\User;
use App\TeamEvent\LeaveTimesheetSync;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Safety net for LeaveTimesheetSync: when a person opens their dashboard or timesheet, any approved
 * leave of theirs that is not in the timesheet yet is put there. This covers leave that was approved
 * before the feature existed. Normally the entries are created right when the leave is approved.
 */
final class LeaveTimesheetSubscriber implements EventSubscriberInterface
{
    private const ROUTES = ['dashboard', 'timesheet', 'timesheet_paginated', 'quick_entry', 'calendar'];

    public function __construct(private readonly Security $security, private readonly LeaveTimesheetSync $sync)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 0],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!($user instanceof User)) {
            return;
        }

        try {
            $this->sync->syncUser($user);
        } catch (\Throwable) {
            // never let this break the page
        }
    }
}
