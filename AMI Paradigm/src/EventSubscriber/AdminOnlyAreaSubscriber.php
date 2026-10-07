<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Closes two areas of the app:
 * - the Administration menu (customers, projects, phases, activities, tags), to everybody except the System-Admin
 *   (the Administrator and System-Admin roles)
 * - everything in the System menu (users, roles, teams, plugins, settings, doctor),
 *   to everybody except the System-Admin
 *
 * The menu entries are hidden in MenuSubscriber; this makes sure the pages and the matching
 * API calls cannot be reached by typing the address either. Looking things up (the lists a
 * time entry form needs, a project's detail page) stays possible for everyone as before.
 */
final class AdminOnlyAreaSubscriber implements EventSubscriberInterface
{
    /** Administrators only: a route is closed when its name starts with one of these */
    private const ADMIN_ONLY_ROUTE_PREFIXES = [
        // customers
        'admin_customer', 'customer_team_create', 'post_customer', 'patch_customer', 'delete_customer',
        // projects
        'admin_project', 'project_team_create', 'post_project', 'patch_project', 'delete_project',
        // phases (project > phase > activity > task)
        'admin_phase',
        // activities: the pages and every change; the lists a time entry form reads (get_activities ...) stay open
        'admin_activity', 'activity_details', 'activity_team_create', 'activity_export', 'project_activities',
        'post_activity', 'patch_activity', 'delete_activity', 'app_api_activity_meta',
        // tags: the pages and deleting; adding a tag while writing a time entry (post_tag) stays open
        'tags', 'delete_tag',
    ];

    /** System-Admin only: the System menu */
    private const SYSTEM_ADMIN_ONLY_ROUTE_PREFIXES = [
        'admin_user', 'admin_team', 'plugins', 'system_configuration', 'doctor',
    ];

    public function __construct(private readonly Security $security)
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
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route)) {
            return;
        }

        $systemOnly = $this->startsWithAny($route, self::SYSTEM_ADMIN_ONLY_ROUTE_PREFIXES);
        $adminOnly = $this->startsWithAny($route, self::ADMIN_ONLY_ROUTE_PREFIXES);
        if (!$systemOnly && !$adminOnly) {
            return;
        }

        $user = $this->security->getUser();
        // nobody logged in: the normal login check takes care of it
        if (!($user instanceof User)) {
            return;
        }

        if ($systemOnly && !$user->isSuperAdmin()) {
            throw new AccessDeniedException('Only the system administrator can open this page.');
        }

        // The Administration menu is for the System-Admin only as well.
        // Previous rule, which also let the Administrator role (project managers) in:
        // if ($adminOnly && !$user->isAdmin() && !$user->isSuperAdmin()) {
        if ($adminOnly && !$user->isSuperAdmin()) {
            throw new AccessDeniedException('Only the system administrator can open this page.');
        }
    }

    /**
     * @param array<string> $prefixes
     */
    private function startsWithAny(string $route, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
