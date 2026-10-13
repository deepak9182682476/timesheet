<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber\Actions;

use App\Entity\User;
use App\Event\PageActionsEvent;
use App\EventSubscriber\AdminOnlyAreaSubscriber;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The action menus (the "..." buttons and page actions) still offered links like Edit, Permissions,
 * Activities or Create activity to people who cannot open those pages (they got "403 Page is restricted").
 * This removes every action that points to a page closed by AdminOnlyAreaSubscriber, after all menus are built.
 */
final class HideClosedActionsSubscriber implements EventSubscriberInterface
{
    private const ACTION_NAMES = [
        'activities', 'activity', 'customer', 'customers', 'dashboard', 'project', 'projects',
        'tag', 'tags', 'team', 'teams', 'timesheet', 'timesheet_team', 'timesheets', 'timesheets_team',
        'user', 'users', 'user_forms', 'user_permissions', 'invoice', 'invoice_template', 'invoice_templates',
    ];

    public function __construct(private readonly RouterInterface $router)
    {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [];
        foreach (self::ACTION_NAMES as $name) {
            // after all the subscribers that add actions (they run at 1000)
            $events['actions.' . $name] = ['hideClosed', -1000];
        }

        return $events;
    }

    public function hideClosed(PageActionsEvent $event): void
    {
        $user = $event->getUser();
        if (!($user instanceof User) || $user->isSuperAdmin()) {
            return;
        }

        $actions = $event->getActions();
        foreach ($actions as $key => $action) {
            if (!\is_array($action)) {
                continue;
            }
            if (\array_key_exists('children', $action) && \is_array($action['children'])) {
                $children = array_filter($action['children'], fn ($child) => !\is_array($child) || !$this->isClosed($child, $user));
                if (\count(array_filter($children, fn ($child) => $child !== null)) === 0) {
                    $event->removeAction($key);
                } elseif (\count($children) !== \count($action['children'])) {
                    $action['children'] = $children;
                    $event->replaceAction($key, $action);
                }
                continue;
            }
            if ($this->isClosed($action, $user)) {
                $event->removeAction($key);
            }
        }

        // no divider at the start, at the end or twice in a row
        $previous = null;
        foreach ($event->getActions() as $key => $action) {
            if ($action === null && ($previous === null || $previous === 'divider')) {
                $event->removeAction($key);
                continue;
            }
            $previous = $action === null ? 'divider' : 'action';
        }
        $remaining = $event->getActions();
        while (\count($remaining) > 0 && end($remaining) === null) {
            $event->removeAction((string) array_key_last($remaining));
            $remaining = $event->getActions();
        }
    }

    /**
     * @param array<mixed> $action
     */
    private function isClosed(array $action, User $user): bool
    {
        $url = $action['url'] ?? null;
        if (!\is_string($url) || $url === '' || $url === '#') {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!\is_string($path)) {
            return false;
        }
        // the router knows the path without the base URL (when the app does not run in the web root)
        $base = $this->router->getContext()->getBaseUrl();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, \strlen($base));
        }

        $context = $this->router->getContext();
        $method = $context->getMethod();
        try {
            // links are opened with GET; API links (like Create copy) say their method in data-method
            $linkMethod = $action['attr']['data-method'] ?? 'GET';
            $context->setMethod(\is_string($linkMethod) ? strtoupper($linkMethod) : 'GET');
            $route = $this->router->match($path)['_route'] ?? null;
        } catch (\Throwable) {
            $route = null;
        } finally {
            $context->setMethod($method);
        }

        return \is_string($route) && AdminOnlyAreaSubscriber::isClosedFor($route, $user);
    }
}
