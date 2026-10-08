<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Form\Type\BillableType;
use App\Form\Type\BudgetType;
use App\Form\Type\DurationType;
use App\Form\Type\MetaFieldsCollectionType;
use App\Form\Type\YesNoType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;

trait EntityFormTrait
{
    use ColorTrait;

    public function addCommonFields(FormBuilderInterface $builder, array $options): void
    {
        $this->addColor($builder);

        $showMoney = $options['include_budget'];
        $showTime = $options['include_time'];
        $showBudget = $showMoney || $showTime;

        if ($showMoney) {
            $builder->add('budget', MoneyType::class, [
                'documentation' => [
                    'description' => 'The money budget',
                ],
                'empty_data' => '0.00',
                'label' => 'budget',
                'required' => false,
                'currency' => $options['currency'],
            ]);
        }

        if ($showTime) {
            $builder->add('timeBudget', DurationType::class, [
                'empty_data' => 0,
                'label' => 'timeBudget',
                'icon' => 'clock',
                'required' => false,
                // a quota can be left at 0 (none) and can be far more than one day's entry (which stops at 10 hours)
                'max_hours' => 100000,
                'attr' => ['data-min-hours' => '0', 'data-max-hours' => '100000'],
            ]);
        }

        if ($showBudget) {
            $builder->add('budgetType', BudgetType::class);
        }

        $builder->add('metaFields', MetaFieldsCollectionType::class);

        $builder
            ->add('visible', YesNoType::class, [
                'label' => 'visible',
                'help' => 'help.visible',
            ])
            // Billable is hidden for now: remove the comment marks to bring it back
            // ->add('billable', BillableType::class)
        ;
    }
}
