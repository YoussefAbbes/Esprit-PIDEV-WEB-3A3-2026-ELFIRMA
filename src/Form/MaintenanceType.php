<?php

namespace App\Form;

use App\Entity\Maintenance;
use App\Entity\Equipement;
use App\Enum\MaintenanceStatut;
use App\Enum\MaintenancePriorite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MaintenanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder

            // ✅ TYPE
            ->add('typeM', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'Ex: Révision, réparation...'
                ]
            ])

            // ✅ DATE
            ->add('dateM', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'empty_data' => null,
                'invalid_message' => 'Date invalide'
            ])

            // ✅ DESCRIPTION
            ->add('description', TextareaType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'Détails de la maintenance'
                ]
            ])

            // ✅ COUT
            ->add('cout', NumberType::class, [
                'required' => true,
                'invalid_message' => 'Le coût doit être un nombre valide'
            ])

            // ✅ STATUT
            ->add('statut', ChoiceType::class, [
                'choices' => [
                    'Planifiée' => MaintenanceStatut::PLANIFIE,
                    'En cours' => MaintenanceStatut::ENCOURS,
                    'Terminée' => MaintenanceStatut::TERMINE,
                ],
                'placeholder' => 'Choisir un statut',
                'required' => false
            ])

            // ✅ PRIORITE
            ->add('priorite', ChoiceType::class, [
                'choices' => [
                    'Basse' => MaintenancePriorite::BASSE,
                    'Moyenne' => MaintenancePriorite::MOYENNE,
                    'Haute' => MaintenancePriorite::HAUTE,
                    'Urgente' => MaintenancePriorite::URGENTE,
                ],
                'placeholder' => 'Choisir une priorité',
                'required' => false
            ])

            // ✅ TECHNICIEN
            ->add('technicien', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'Nom du technicien'
                ]
            ])

            // ✅ EQUIPEMENT (relation)
            ->add('equipement', EntityType::class, [
                'class' => Equipement::class,
                'choice_label' => 'nomEq',
                'placeholder' => 'Choisir un équipement',
                'required' => true
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Maintenance::class,
        ]);
    }
}