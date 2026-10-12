<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\WfhRequest;
use App\Form\Type\DatePickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * A WFH request: the day (or first and last day) and why. The rules are checked in WfhController.
 */
final class WfhRequestForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startDate', DatePickerType::class, [
                'label' => 'From',
                'translation_domain' => false,
                'constraints' => [new NotNull(message: 'Pick the day you work from home.')],
            ])
            ->add('endDate', DatePickerType::class, [
                'label' => 'To (last day)',
                'translation_domain' => false,
                'required' => false,
                'help' => 'Leave empty for a single day.',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason',
                'translation_domain' => false,
                'required' => true,
                'attr' => ['rows' => 3, 'maxlength' => 1000, 'placeholder' => 'Why you work from home, for example: internet installation at home'],
                'constraints' => [new NotBlank(message: 'Please give the reason.'), new Length(max: 1000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WfhRequest::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wfh_request',
        ]);
    }
}
