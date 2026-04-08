<?php

namespace App\Form;

use App\Entity\Culture;
use App\Entity\Parcelle;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CultureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomCulture', TextType::class, [
                'label' => 'Crop Name',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter crop name'],
            ])
            ->add('variete', TextType::class, [
                'label' => 'Variety',
                'required' => false,
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter variety'],
            ])
            ->add('parcelle', EntityType::class, [
                'class' => Parcelle::class,
                'choice_label' => 'nom',
                'label' => 'Parcel',
                'required' => false,
                'placeholder' => 'Select a parcel',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('datePlantation', DateType::class, [
                'label' => 'Planting Date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('dateRecoltePrevue', DateType::class, [
                'label' => 'Expected Harvest Date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('dateRecolteReelle', DateType::class, [
                'label' => 'Actual Harvest Date',
                'widget' => 'single_text',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('quantitePlantee', NumberType::class, [
                'label' => 'Quantity Planted',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter quantity'],
            ])
            ->add('quantiteRecoltee', NumberType::class, [
                'label' => 'Quantity Harvested',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter quantity'],
            ])
            ->add('coutProduction', NumberType::class, [
                'label' => 'Production Cost',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter cost'],
            ])
            ->add('rendement', NumberType::class, [
                'label' => 'Yield',
                'required' => false,
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter yield'],
            ])
            ->add('statut', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Harvested' => 'Harvested',
                    'In Progress' => 'In Progress',
                    'Planned' => 'Planned',
                ],
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('observations', TextareaType::class, [
                'label' => 'Observations',
                'required' => false,
                'attr' => ['class' => 'form-textarea', 'rows' => 3, 'placeholder' => 'Enter observations'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Culture::class,
        ]);
    }
}
