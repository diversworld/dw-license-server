<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Diversworld Lizenzverwaltung');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Übersicht', 'fa fa-home');
        yield MenuItem::linkTo(CustomerCrudController::class, 'Kunden', 'fa fa-users');
        yield MenuItem::linkTo(ProductCrudController::class, 'Module', 'fa fa-cubes');
        yield MenuItem::linkTo(LicenseCrudController::class, 'Lizenzen', 'fa fa-key');
        yield MenuItem::linkTo(ActivationCrudController::class, 'Installationen', 'fa fa-globe');
        yield MenuItem::linkTo(UserCrudController::class, 'Administratoren', 'fa fa-user');
        yield MenuItem::linkToLogout('Abmelden', 'fa fa-sign-out');
    }
}
