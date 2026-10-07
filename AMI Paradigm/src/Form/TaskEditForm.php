<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Form\Type\DatePickerType;
use App\Form\Type\DurationType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create or change a task.
 */
final class TaskEditForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Task',
                'attr' => ['autofocus' => 'autofocus'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'description',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('project', EntityType::class, [
                'label' => 'project',
                'class' => Project::class,
                'choices' => $options['projects'],
                'choice_label' => static fn (Project $project) => $project->getCustomer()->getName() . ' – ' . $project->getName(),
                'placeholder' => '',
            ])
            ->add('activity', EntityType::class, [
                'label' => 'activity',
                'class' => Activity::class,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('a')->andWhere('a.visible = true')->orderBy('a.name', 'ASC'),
                'choice_label' => static fn (Activity $activity) => $activity->getName() . ($activity->getProject() !== null ? ' (' . $activity->getProject()->getName() . ')' : ''),
                // the projects each activity belongs to: the script in tasks/edit.html.twig narrows the list to the picked project
                'choice_attr' => static fn (Activity $activity) => ['data-projects' => implode(',', $options['activity_projects'][(int) $activity->getId()] ?? [])],
                'required' => false,
                'placeholder' => 'Any activity',
                'help' => 'Optional. Pick the project first: only its activities are listed. With an activity set, the task is only offered on time entries with that activity.',
            ])
            ->add('assignee', EntityType::class, [
                'label' => 'Assigned to',
                'class' => User::class,
                'choices' => $options['assignees'],
                'choice_label' => static fn (User $user) => $user->getDisplayName(),
                'placeholder' => '',
            ])
            ->add('dueDate', DatePickerType::class, [
                'label' => 'Due date',
                'required' => false,
            ])
            ->add('estimate', DurationType::class, [
                'label' => 'Estimated hours',
                'required' => false,
                'max_hours' => 10000,
                // an estimate may be longer than one day's entry (which stops at 10 hours)
                'attr' => ['data-max-hours' => 1000],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'status',
                'choices' => [
                    'Open' => Task::STATUS_OPEN,
                    'In progress' => Task::STATUS_IN_PROGRESS,
                    'Done' => Task::STATUS_DONE,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Task::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'task_edit',
            'projects' => [],
            'assignees' => [],
            'activity_projects' => [],
        ]);
        $resolver->setAllowedTypes('projects', 'array');
        $resolver->setAllowedTypes('assignees', 'array');
        $resolver->setAllowedTypes('activity_projects', 'array');
    }
}
