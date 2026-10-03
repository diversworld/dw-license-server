<?php
namespace App\Controller\Admin;
use App\Entity\ReminderDelivery;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Field\{AssociationField, DateTimeField, IntegerField, TextField, ArrayField};
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted('ROLE_VIEWER')]
final class ReminderDeliveryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return ReminderDelivery::class; }
    public function configureActions(Actions $actions): Actions { return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE)->add(Crud::PAGE_INDEX, Action::DETAIL); }
    public function configureCrud(Crud $crud): Crud { return $crud->setEntityLabelInSingular('expiry_reminder.delivery')->setEntityLabelInPlural('expiry_reminder.deliveries'); }
    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('license', 'license_action.license');
        yield TextField::new('recipient', 'expiry_reminder.recipient');
        yield DateTimeField::new('expiry', 'license_action.expires_at');
        yield IntegerField::new('daysBefore', 'expiry_reminder.days');
        yield TextField::new('status', 'expiry_reminder.status');
        yield IntegerField::new('attempts', 'expiry_reminder.attempts');
        yield ArrayField::new('history', 'expiry_reminder.history')->setTemplatePath('admin/field/reminder_history.html.twig')->hideOnIndex();
    }
}
