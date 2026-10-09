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
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Asking for a comp-off: the day that was worked (and why, and how long), and the day to take off for it.
 * The rules are checked in CompOffController.
 */
final class CompOffRequestForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('compWorkedDate', DatePickerType::class, [
                'label' => 'Date worked',
                'translation_domain' => false,
                'constraints' => [new NotNull(message: 'Pick the day you worked.')],
                'help' => 'The weekend day, festival or holiday, or the day of the night shift. Up to ' . $options['claim_days'] . ' days back.',
            ])
            ->add('compReason', ChoiceType::class, [
                'label' => 'Worked on',
                'translation_domain' => false,
                'choices' => array_flip(TeamEvent::COMP_REASONS),
                'expanded' => true,
                'constraints' => [new NotBlank(message: 'Say why you worked that day.')],
            ])
            ->add('compWorkedHours', NumberType::class, [
                'label' => 'Hours worked',
                'translation_domain' => false,
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 0.5, 'max' => 14, 'step' => 0.5, 'placeholder' => 'For example 8'],
                'constraints' => [new NotNull(message: 'Enter the hours you worked.')],
                'help' => 'At least ' . $options['full_day_hours'] . ' hours for a full day off, ' . $options['half_day_hours'] . ' for a half day.',
            ])
            ->add('startDate', DatePickerType::class, [
                'label' => 'Comp-off date',
                'translation_domain' => false,
                'constraints' => [new NotNull(message: 'Pick the day you want off.')],
                'help' => 'A working day after the day you worked, within ' . $options['use_within_days'] . ' days of it.',
            ])
            ->add('compHalfDay', ChoiceType::class, [
                'label' => 'Take',
                'translation_domain' => false,
                'choices' => ['Full day' => false, 'Half day' => true],
                'expanded' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Work done',
                'translation_domain' => false,
                'required' => true,
                'attr' => ['rows' => 3, 'maxlength' => 1000, 'placeholder' => 'What you worked on that day, for example: HMEL go-live support'],
                'constraints' => [new NotBlank(message: 'Say what you worked on that day.'), new Length(max: 1000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TeamEvent::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'comp_off_request',
            'claim_days' => 60,
            'use_within_days' => 60,
            'full_day_hours' => 8,
            'half_day_hours' => 4,
        ]);
    }
}
