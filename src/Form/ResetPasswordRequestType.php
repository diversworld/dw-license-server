<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordRequestType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-Mail-Adresse',
                'mapped' => false,

                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'name@beispiel.de',
                ],

                'constraints' => [
                    new NotBlank(
                        message:
                            'Bitte gib deine E-Mail-Adresse ein.'
                    ),

                    new Email(
                        message:
                            'Bitte gib eine gültige E-Mail-Adresse ein.'
                    ),
                ],
            ]);
    }
}