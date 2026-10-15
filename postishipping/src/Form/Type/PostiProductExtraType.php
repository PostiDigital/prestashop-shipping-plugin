<?php

namespace Posti\PrestaShop\Postishipping\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PostiProductExtraType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('posti_hs_tariff', TextType::class, [
                'label' => $options['hs_tariff_label'],
                'required' => false,
                'attr' => ['class' => 'small-input'],
                'help' => $options['hs_tariff_help'],
            ])
            ->add('posti_country_of_origin', ChoiceType::class, [
                'label' => $options['country_label'],
                'required' => false,
                'choices' => $options['country_choices'],
                'help' => $options['country_help'],
            ])
            ->add('posti_dg_weight', IntegerType::class, [
                'label' => $options['dg_weight_label'],
                'required' => false,
                'attr' => ['min' => 0, 'class' => 'small-input'],
                'help' => $options['dg_weight_help'],
                'unit' => 'g',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'Posti',
            'required' => false,
            'translation_domain' => 'Modules.Postishipping.Admin',
            'hs_tariff_label' => 'HS tariff number',
            'hs_tariff_help' => '',
            'country_label' => 'Country of origin',
            'country_help' => '',
            'country_choices' => [],
            'dg_weight_label' => 'DG weight',
            'dg_weight_help' => '',
        ]);
    }
}
