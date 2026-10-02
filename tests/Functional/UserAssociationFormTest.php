<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

class UserAssociationFormTest extends KernelTestCase
{
    public function testUsersCanBeDisplayedAndSelectedInAssociationFields(): void
    {
        self::bootKernel();
        $namedUser = (new User())->setFirstname('Anna')->setLastname('Admin')->setEmail('anna@example.org');
        $emailOnlyUser = (new User())->setEmail('admin@example.org');

        $form = static::getContainer()->get(FormFactoryInterface::class)->create(EntityType::class, null, [
            'class' => User::class,
            'choices' => [$namedUser, $emailOnlyUser],
            'choice_value' => 'id',
            'csrf_protection' => false,
            'validation_groups' => false,
        ]);

        $choices = $form->createView()->vars['choices'];
        self::assertSame('Anna Admin', $choices[0]->label);
        self::assertSame('admin@example.org', $choices[1]->label);

        $form->submit($choices[0]->value);
        self::assertTrue($form->isSynchronized());
        self::assertSame($namedUser, $form->getData());
    }
}
