<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class InstallationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tenant', TextType::class, ['label' => 'Mandantenkennung', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 180)]])
            ->add('domain', TextType::class, ['label' => 'Domain (ohne Protokoll oder Pfad)', 'constraints' => [new Assert\NotBlank(), new Assert\Hostname(requireTld: true), new Assert\Length(max: 253)]]);
    }
}
