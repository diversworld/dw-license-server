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
        yield FormField::addFieldset('ui.personal')
            ->setIcon('fa fa-user');

        yield TextField::new('firstname', 'ui.first_name')->setFormTypeOption('constraints', [new \Symfony\Component\Validator\Constraints\NotBlank()])
            ->setColumns(6);

        yield TextField::new('lastname', 'ui.last_name')->setFormTypeOption('constraints', [new \Symfony\Component\Validator\Constraints\NotBlank()])
            ->setColumns(6);

        yield EmailField::new('email', 'ui.email')->setFormTypeOption('constraints', [new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Email()])
            ->setColumns(12)
            ->setHelp(
                'ui.email_help'
            );

        yield FormField::addFieldset('ui.address')
            ->setIcon('fa fa-address-card');

        yield TextField::new('street', 'ui.street')
            ->setColumns(9);

        yield TextField::new('postalCode', 'ui.postal_code')
            ->setColumns(4);

        yield TextField::new('city', 'ui.city')
            ->setColumns(8);

        yield FormField::addFieldset('ui.contact')
            ->setIcon('fa fa-phone');

        yield TextField::new('mobile', 'ui.mobile')
            ->setColumns(6);

        yield TextField::new('phone', 'ui.phone')
            ->setColumns(6);

        yield FormField::addFieldset('ui.permissions')
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
        yield ChoiceField::new('roles', 'ui.roles')->setChoices($roles)->allowMultipleChoices()
            ->setColumns(6);

        yield BooleanField::new('globalAccess', 'customer_scope.global')->renderAsSwitch(false);
        yield \EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField::new('customers', 'customer_scope.assignments')->setFormTypeOption('by_reference', false);

        yield BooleanField::new('active', 'ui.active')
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