<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Aktuelles Kennwort',
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'current-password',
                ],
                'constraints' => [
                    new NotBlank(
                        message:
                            'Bitte gib dein aktuelles Kennwort ein.'
                    ),
                    new UserPassword(
                        message:
                            'Das aktuelle Kennwort ist nicht korrekt.'
                    ),
                ],
            ])

            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,

                'first_options' => [
                    'label' => 'Neues Kennwort',
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],

                'second_options' => [
                    'label' => 'Neues Kennwort wiederholen',
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],

                'invalid_message' =>
                    'Die beiden Kennwörter stimmen nicht überein.',

                'constraints' => [
                    new NotBlank(
                        message:
                            'Bitte gib ein neues Kennwort ein.'
                    ),

                    new Length(
                        min: 12,
                        max: 4096,
                        minMessage:
                            'Das Kennwort muss mindestens {{ limit }} Zeichen lang sein.'
                    ),
                ],
            ]);
    }
}