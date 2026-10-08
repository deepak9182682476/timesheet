<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Toolbar;

use App\Repository\Query\ExportQuery;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Defines the form used for filtering timesheet entries for exports.
 * @extends AbstractType<ExportQuery>
 */
final class ExportToolbarForm extends AbstractType
{
    use ToolbarFormTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addSearchTermInputField($builder);
        $this->addDateRange($builder, ['timezone' => $options['timezone']]);
        // The customer filter is hidden for now, the export is filtered by project:
        // remove the comment marks to bring it back
        // $this->addCustomerMultiChoice($builder, ['start_date_param' => null, 'end_date_param' => null, 'ignore_date' => true], true);
        // One plain list of projects, sorted by name (the earlier list was grouped by customer:
        // remove 'group_by' to get the groups back)
        $this->addProjectMultiChoice($builder, ['ignore_date' => true, 'group_by' => null], true, true);
        // The activity filter is hidden for now: remove the comment marks to bring it back
        // $this->addActivitySelect($builder, [], true, true, false);
        // Tags are hidden for now: remove the comment marks to bring them back
        // $this->addTagInputField($builder);
        if ($options['include_user']) {
            // People who are not admins only get their own people to choose from (and themselves),
            // see ExportController::getTeamUsers(). null = everybody (admins).
            if (\is_array($options['team_users'])) {
                $this->addUsersChoice($builder, 'users', ['choices' => $options['team_users']]);
            } else {
                $this->addUsersChoice($builder);
            }
            $this->addTeamsChoice($builder);
        }
        $this->addExportStateChoice($builder);
        // The "Records" filter (running / stopped) is hidden for now, the export keeps its default (stopped records): remove the comment marks to bring it back
        // $this->addTimesheetStateChoice($builder);
        // Billable is hidden for now: remove the comment marks to bring it back
        // $this->addBillableChoice($builder);
        $builder->add('renderer', HiddenType::class, []);
        if ($options['include_export']) {
            $builder->add('markAsExported', HiddenType::class, [
                'label' => 'mark_as_exported',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExportQuery::class,
            'csrf_protection' => false,
            'include_user' => true,
            'include_export' => true,
            'team_users' => null,
            'timezone' => date_default_timezone_get(),
        ]);
    }
}
