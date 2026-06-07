<?php

namespace App\Form;

use App\Entity\Equipement;
use App\Enum\EquipementEtat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EquipementType extends AbstractType
{
public function buildForm(FormBuilderInterface $builder, array $options)
{
$builder
->add('nomEq', TextType::class, [
'required' => true,
'attr' => [
'placeholder' => 'Equipment name'
]
])

        ->add('typeEq', TextType::class, [
            'required' => true,
            'attr' => [
                'placeholder' => 'Equipment type'
            ]
        ])

        ->add('dateAchat', DateType::class, [
            'widget' => 'single_text',
            'required' => false,
            'empty_data' => null, 
            'invalid_message' => 'Invalid date'
        ])

        ->add('etat', ChoiceType::class, [
            'choices' => [
                'Available' => EquipementEtat::DISPONIBLE,
                'Under Maintenance' => EquipementEtat::MAINTENANCE,
                'Broken Down' => EquipementEtat::PANNE,
            ],
            'placeholder' => 'Choose a status',
            'required' => true
        ])

        ->add('coutAchat', NumberType::class, [
            'required' => true,
            'invalid_message' => 'Cost must be a valid number'
        ])

        ->add('descriptionEq', TextareaType::class, [
            'required' => true,
            'attr' => [
                'placeholder' => 'Description'
            ]
        ]);
}

public function configureOptions(OptionsResolver $resolver)
{
    $resolver->setDefaults([
        'data_class' => Equipement::class,
    ]);
}

}