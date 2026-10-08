<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Extension;

use App\Form\TimesheetAdminEditForm;
use App\Form\TimesheetEditForm;
use App\Form\TimesheetMultiUserEditForm;
use App\Form\Type\QuickEntryWeekType;
use App\WorkModel\WorkModelService;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * On a project with a model the activity is not picked on its own: it comes with the picked item
 * (Epic > Feature > User Story > Activity). When the time entry form is sent, the activity of the
 * picked item is filled in here, before the form looks at what was sent.
 */
final class TimesheetWorkItemExtension extends AbstractTypeExtension
{
    public function __construct(private readonly WorkModelService $models)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        // the last one is a row of the "Weekly hours" grid, which sends the same three fields
        return [TimesheetEditForm::class, TimesheetAdminEditForm::class, TimesheetMultiUserEditForm::class, QuickEntryWeekType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event): void {
                $data = $event->getData();
                if (!\is_array($data)) {
                    return;
                }

                $item = $data['metaFields'][WorkModelService::META_ITEM]['value'] ?? '';
                if (!\is_string($item) || $item === '' || !ctype_digit($item)) {
                    return;
                }

                $activity = $this->models->getActivityFor((int) $item);
                if ($activity === null || $activity->getProject() === null) {
                    return;
                }

                // only when the item really belongs to the project that was sent
                if ((string) ($data['project'] ?? '') !== (string) $activity->getProject()->getId()) {
                    return;
                }

                $data['activity'] = (string) $activity->getId();
                $event->setData($data);
            },
            // before the form rebuilds its list of activities for the project that was sent
            100
        );
    }
}
