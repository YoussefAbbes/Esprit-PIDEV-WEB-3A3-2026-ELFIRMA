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
'placeholder' => 'Nom équipement'
]
])

        ->add('typeEq', TextType::class, [
            'required' => true,
            'attr' => [
                'placeholder' => 'Type équipement'
            ]
        ])

        ->add('dateAchat', DateType::class, [
            'widget' => 'single_text',
            'required' => false,
            'empty_data' => null, 
            'invalid_message' => 'Date invalide'
        ])

        ->add('etat', ChoiceType::class, [
            'choices' => [
                'Disponible' => EquipementEtat::DISPONIBLE,
                'Maintenance' => EquipementEtat::MAINTENANCE,
                'Panne' => EquipementEtat::PANNE,
            ],
            'placeholder' => 'Choisir un état',
            'required' => true
        ])

        ->add('coutAchat', NumberType::class, [
            'required' => true,
            'invalid_message' => 'Le coût doit être un nombre valide'
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