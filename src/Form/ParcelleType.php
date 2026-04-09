<?php

namespace App\Form;

use App\Entity\Parcelle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParcelleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Name',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter parcel name'],
            ])
            ->add('localisation', TextType::class, [
                'label' => 'Location',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter location'],
            ])
            ->add('superficie', NumberType::class, [
                'label' => 'Area (ha)',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter area in hectares'],
            ])
            ->add('typeSol', ChoiceType::class, [
                'label' => 'Soil Type',
                'required' => true,
                'choices' => [
                    'Sandy' => 'Sandy',
                    'Loamy' => 'Loamy',
                    'Clay' => 'Clay',
                    'Humus' => 'Humus',
                ],
                'placeholder' => 'Select soil type',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('statut', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Available' => 'Available',
                    'Occupied' => 'Occupied',
                    'Resting' => 'Resting',
                ],
                'required' => true,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('dateCreation', DateType::class, [
                'label' => 'Creation Date',
                'widget' => 'single_text',
                'required' => true,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'required' => true,
                'scale' => 6,
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter latitude'],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'required' => true,
                'scale' => 6,
                'attr' => ['class' => 'form-input', 'placeholder' => 'Enter longitude'],
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Parcel Image',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'accept' => 'image/*',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Parcelle::class,
        ]);
    }
}
