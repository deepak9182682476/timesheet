<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\Activity;
use App\Entity\ActivityTask;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create or change a standard task of an activity (administrators only).
 */
final class ActivityTaskEditForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('activity', EntityType::class, [
                'label' => 'activity',
                'class' => Activity::class,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('a')->orderBy('a.name', 'ASC'),
                'choice_label' => static fn (Activity $activity) => $activity->getName() . ($activity->getProject() !== null ? ' (' . $activity->getProject()->getName() . ')' : ''),
                'placeholder' => '',
            ])
            ->add('name', TextType::class, [
                'label' => 'Task',
                'help' => 'Offered to everybody on a time entry once this activity is picked.',
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Order',
                'required' => false,
                'help' => 'Tasks are listed from the lowest number to the highest.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActivityTask::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'activity_task_edit',
        ]);
    }
}
