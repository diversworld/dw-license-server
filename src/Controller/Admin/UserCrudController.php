<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('USER_MANAGE')]
class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE, Action::NEW)
            ->setPermission(Action::EDIT, 'USER_MANAGE');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Persönliche Daten')
            ->setIcon('fa fa-user');

        yield TextField::new('firstname', 'Vorname')
            ->setColumns(6);

        yield TextField::new('lastname', 'Nachname')
            ->setColumns(6);

        yield EmailField::new('email', 'E-Mail-Adresse')
            ->setColumns(12)
            ->setHelp(
                'Diese E-Mail-Adresse wird auch für die Anmeldung verwendet.'
            );

        yield FormField::addFieldset('Anschrift')
            ->setIcon('fa fa-address-card');

        yield TextField::new('street', 'Straße')
            ->setColumns(9);

        yield TextField::new('postalCode', 'PLZ')
            ->setColumns(4);

        yield TextField::new('city', 'Wohnort')
            ->setColumns(8);

        yield FormField::addFieldset('Kontaktdaten')
            ->setIcon('fa fa-phone');

        yield TextField::new('mobile', 'Mobil')
            ->setColumns(6);

        yield TextField::new('phone', 'Telefon')
            ->setColumns(6);

        yield FormField::addFieldset('Berechtigungen')
            ->setIcon('fa fa-shield');

        $roles = ['role.admin' => 'ROLE_ADMIN', 'role.support' => 'ROLE_SUPPORT', 'role.sales' => 'ROLE_SALES', 'role.viewer' => 'ROLE_VIEWER'];
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $roles['role.super_admin'] = 'ROLE_SUPER_ADMIN';
        }
        yield ChoiceField::new('roles', 'Rollen')->setChoices($roles)->allowMultipleChoices()
            ->setColumns(6);

        yield BooleanField::new('active', 'Aktiv')
            ->renderAsSwitch(false)
            ->setColumns(6);
    }
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        if ((in_array('ROLE_SUPER_ADMIN', $original['roles'] ?? [], true)
            || in_array('ROLE_SUPER_ADMIN', $entityInstance->getRoles(), true)) && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
}