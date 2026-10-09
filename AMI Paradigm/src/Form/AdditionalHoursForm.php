<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\AdditionalHours;
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
 * Additional hours worked on a weekend, a festival or holiday, or a night shift. The rules are checked in
 * AdditionalHoursController.
 */
final class AdditionalHoursForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workDate', DatePickerType::class, [
                'label' => 'Date',
                'translation_domain' => false,
                'constraints' => [new NotNull(message: 'Pick the day you worked.')],
                'help' => 'The weekend day, festival or holiday, or the day of the night shift. Up to ' . AdditionalHours::CREDIT_VALID_DAYS . ' days back.',
            ])
            ->add('reason', ChoiceType::class, [
                'label' => 'Worked on',
                'translation_domain' => false,
                'choices' => array_flip(AdditionalHours::REASONS),
                'expanded' => true,
                'label_attr' => ['class' => 'radio-custom'],
                'constraints' => [new NotBlank(message: 'Say why you worked that day.')],
            ])
            ->add('hours', NumberType::class, [
                'label' => 'Extra hours',
                'translation_domain' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0.25, 'max' => 24, 'step' => 0.25, 'placeholder' => 'For example 4.5', 'class' => 'additional-hours-input'],
                'constraints' => [new NotNull(message: 'Enter the hours you worked.')],
                'help' => 'A day is ' . AdditionalHours::DAY_HOURS . ' hours: ' . (AdditionalHours::DAY_HOURS / 4) . ' h is a quarter day, ' . (AdditionalHours::DAY_HOURS / 2) . ' h a half day, ' . AdditionalHours::DAY_HOURS . ' h a full day.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Work done',
                'translation_domain' => false,
                'required' => true,
                'attr' => ['rows' => 3, 'maxlength' => 1000, 'placeholder' => 'What you worked on, for example: HMEL go-live support'],
                'constraints' => [new NotBlank(message: 'Say what you worked on.'), new Length(max: 1000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdditionalHours::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'additional_hours',
        ]);
    }
}
