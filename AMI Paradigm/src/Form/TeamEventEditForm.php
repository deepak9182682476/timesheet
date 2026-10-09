<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\TeamEvent;
use App\Form\Type\DatePickerType;
use App\Form\Type\TimePickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Create or change an event (team outing, holiday, leave ...).
 */
final class TeamEventEditForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Event',
                'attr' => ['autofocus' => 'autofocus', 'placeholder' => 'Team outing'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => $options['types'],
                'translation_domain' => false,
            ])
            // activity: its hours are logged in everybody's timesheet; information: only shown
            ->add('kind', ChoiceType::class, [
                'label' => 'Kind',
                'choices' => [
                    'Activity (logged in the timesheets of everybody it applies to)' => TeamEvent::KIND_ACTIVITY,
                    'Information only (shown, nothing is logged)' => TeamEvent::KIND_INFORMATION,
                ],
                'expanded' => true,
                'translation_domain' => false,
                'help' => 'An activity needs a start and an end time: those hours are logged under Non-Project Activities > Team Events.',
            ])
            ->add('startDate', DatePickerType::class, [
                'label' => 'From',
            ])
            ->add('startTime', TimePickerType::class, [
                'label' => 'From time',
                'required' => false,
                'help' => 'Optional. Leave both times empty for a whole-day event.',
            ])
            ->add('endDate', DatePickerType::class, [
                'label' => 'To',
                'required' => false,
                'help' => 'Leave empty for a single day.',
            ])
            ->add('endTime', TimePickerType::class, [
                'label' => 'To time',
                'required' => false,
            ])
            // "all", "t:<team id>" or "u:<user id>" - turned into the team/person by the controller
            ->add('audience', ChoiceType::class, [
                'label' => 'Applies to',
                'mapped' => false,
                'choices' => $options['audiences'],
                'data' => $options['audience'],
                'placeholder' => '',
                'translation_domain' => false,
                'constraints' => [new NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TeamEvent::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'team_event_edit',
            'audiences' => [],
            'audience' => null,
            'types' => [],
        ]);
        $resolver->setAllowedTypes('types', 'array');
        $resolver->setAllowedTypes('audiences', 'array');
        $resolver->setAllowedTypes('audience', ['null', 'string']);
    }
}
