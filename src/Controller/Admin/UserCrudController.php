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
    public function __construct(private readonly \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $passwordHasher) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->setPermission(Action::NEW, 'USER_MANAGE')
            ->setPermission(Action::EDIT, 'USER_MANAGE');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Persönliche Daten')
            ->setIcon('fa fa-user');

        yield TextField::new('firstname', 'Vorname')->setFormTypeOption('constraints', [new \Symfony\Component\Validator\Constraints\NotBlank()])
            ->setColumns(6);

        yield TextField::new('lastname', 'Nachname')->setFormTypeOption('constraints', [new \Symfony\Component\Validator\Constraints\NotBlank()])
            ->setColumns(6);

        yield EmailField::new('email', 'E-Mail-Adresse')->setFormTypeOption('constraints', [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Email()])
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

        if ($pageName === \EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_NEW) {
            yield TextField::new('plainPassword', 'user_creation.password')
                ->setFormType(\Symfony\Component\Form\Extension\Core\Type\RepeatedType::class)
                ->setFormTypeOptions(['mapped' => false, 'type' => \Symfony\Component\Form\Extension\Core\Type\PasswordType::class,
                    'first_options' => ['label' => 'user_creation.password', 'attr' => ['autocomplete' => 'new-password']],
                    'second_options' => ['label' => 'user_creation.repeat', 'attr' => ['autocomplete' => 'new-password']],
                    'invalid_message' => 'user_creation.mismatch',
                    'constraints' => [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Length(min: 12, max: 4096)]]);
        }

        $roles = \App\Security\RoleCatalog::choices($this->isGranted('ROLE_SUPER_ADMIN'));
        yield ChoiceField::new('roles', 'Rollen')->setChoices($roles)->allowMultipleChoices()
            ->setColumns(6);

        yield BooleanField::new('globalAccess', 'customer_scope.global')->renderAsSwitch(false);
        yield \EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField::new('customers', 'customer_scope.assignments')->setFormTypeOption('by_reference', false);

        yield BooleanField::new('active', 'Aktiv')
            ->renderAsSwitch(false)
            ->setColumns(6);
    }
    public function createEntity(string $entityFqcn): object
    {
        return (new User())->setRoles(['ROLE_CUSTOMER'])->setGlobalAccess(false);
    }

    public function createNewFormBuilder(\EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto $entityDto, \EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore $formOptions, \EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context): \Symfony\Component\Form\FormBuilderInterface
    {
        $builder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        $builder->addEventListener(\Symfony\Component\Form\FormEvents::POST_SUBMIT, function (\Symfony\Component\Form\FormEvent $event): void {
            $form = $event->getForm();
            if ($form->isValid()) {
                $user = $event->getData();
                $user->setPassword($this->passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            }
        }, -1024);

        return $builder;
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->denyAccessUnlessGranted('USER_MANAGE', $entityInstance);
        if (in_array('ROLE_SUPER_ADMIN', $entityInstance->getRoles(), true) && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        if (!$entityInstance->getPassword()) { throw new \LogicException('A validated password is required to create an account.'); }
        parent::persistEntity($entityManager, $entityInstance);
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