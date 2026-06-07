<?php

namespace App\Form;

use App\Entity\Maintenance;
use App\Entity\Equipement;
use App\Entity\Utilisateur;
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
                    'placeholder' => 'Ex: Revision, repair...'
                ]
            ])

            // ✅ DATE
            ->add('dateM', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'empty_data' => null,
                'invalid_message' => 'Invalid date'
            ])

            // ✅ DESCRIPTION
            ->add('description', TextareaType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'Maintenance details'
                ]
            ])

            // ✅ COUT
            ->add('cout', NumberType::class, [
                'required' => true,
                'invalid_message' => 'Cost must be a valid number'
            ])

            // ✅ STATUT
            ->add('statut', ChoiceType::class, [
                'choices' => [
                    'Scheduled' => MaintenanceStatut::PLANIFIE,
                    'In Progress' => MaintenanceStatut::ENCOURS,
                    'Completed' => MaintenanceStatut::TERMINE,
                    'Pending' => MaintenanceStatut::EN_ATTENTE,
                ],
                'placeholder' => 'Choose a status',
                'required' => false
            ])

            // ✅ PRIORITE
            ->add('priorite', ChoiceType::class, [
                'choices' => [
                    'Low' => MaintenancePriorite::BASSE,
                    'Medium' => MaintenancePriorite::MOYENNE,
                    'High' => MaintenancePriorite::HAUTE,
                    'Urgent' => MaintenancePriorite::URGENTE,
                ],
                'placeholder' => 'Choose a priority',
                'required' => false
            ])

            // ✅ TECHNICIEN
            ->add('technicien', EntityType::class, [
                'class' => Utilisateur::class,
                'choice_label' => function (Utilisateur $u) {
                    return $u->getPrenomU() . ' ' . $u->getNomU();
                },
                'placeholder' => 'Choose a technician',
                'query_builder' => function ($repo) {
                    return $repo->createQueryBuilder('u')
                        ->where('u.role_u = :role')
                        ->setParameter('role', 'employee');
                },
                'attr' => [
                    'class' => 'w-full p-2 border rounded focus:ring-2 focus:ring-blue-500'
                ]
            ])

            // ✅ EQUIPEMENT (relation)
            ->add('equipement', EntityType::class, [
                'class' => Equipement::class,
                'choice_label' => 'nomEq',
                'placeholder' => 'Choose equipment',
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