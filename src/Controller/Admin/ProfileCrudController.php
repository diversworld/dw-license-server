<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_VIEWER')]
class ProfileCrudController extends AbstractCrudController
{
    public function __construct(private readonly \App\Service\ProfileImageUpload $images)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityPermission('PROFILE_SELF')
            ->setPageTitle(Crud::PAGE_EDIT, 'ui.my_profile')
            ->setEntityLabelInSingular('ui.profile')
            ->setEntityLabelInPlural('ui.profiles');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(
                Action::INDEX,
                Action::NEW,
                Action::DELETE,
                Action::SAVE_AND_ADD_ANOTHER
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('ui.personal')
            ->setIcon('fa fa-user');

        yield TextField::new('firstname', 'ui.first_name')
            ->setColumns(6);

        yield TextField::new('lastname', 'ui.last_name')
            ->setColumns(6);

        yield EmailField::new('email', 'ui.email')
            ->setColumns(12)
            ->setHelp(
                'ui.email_help'
            );

        yield ImageField::new('profileImage', 'ui.profile_image')
            ->setBasePath('/uploads/profile')
            ->setUploadDir('public/uploads/profile')
            ->setUploadedFileNamePattern($this->images->filename(...))
            ->setFileConstraints($this->images->constraint())
            ->setFormTypeOption('upload_new', $this->images->store(...))
            ->setRequired(false)
            ->setColumns(12)
            ->setHelp('ui.image_limits')
            ->setFormTypeOption('attr', ['accept' => 'image/jpeg,image/png,image/webp']);
        
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
    }

    protected function getRedirectResponseAfterSave(
        AdminContext $context,
        string $action
    ): RedirectResponse {
        // Die Profilverwaltung hat keine Indexseite. Die Profilroute
        // ermittelt stattdessen die Bearbeitungsseite des angemeldeten Benutzers.
        return $this->redirectToRoute('dashboard_profile');
    }

    public function updateEntity(
        EntityManagerInterface $entityManager,
        $entityInstance
    ): void {
        if (!$entityInstance instanceof User) {
            return;
        }

        // Sicherheitsprüfung:
        // Über diesen Controller darf nur das eigene Profil
        // verändert werden.
        if (!$this->getUser() instanceof User || (string) $entityInstance->getId() !== (string) $this->getUser()->getId()) {
            throw $this->createAccessDeniedException(
                'Du darfst nur dein eigenes Profil bearbeiten.'
            );
        }

        $entityInstance->setUpdatedAt(
            new \DateTimeImmutable()
        );

        parent::updateEntity(
            $entityManager,
            $entityInstance
        );
    }
}
