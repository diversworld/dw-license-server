<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\LicenseRepository;
use App\Form\ChangePasswordType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use EasyCorp\Bundle\EasyAdminBundle\Config\Theme;
use EasyCorp\Bundle\EasyAdminBundle\Config\Locale;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;

#[AdminDashboard(routePath: '/admin/{_locale}', routeName: 'admin', routeOptions: ['requirements' => ['_locale' => 'de|en|fr'], 'defaults' => ['_locale' => 'de'],'methods' => ['GET'],],)]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly LicenseRepository $licenseRepository)
    {
    }

    public function index(): Response
    {
        return $this->render(
            'admin/dashboard.html.twig',
            [
                'licenseStatistics' =>
                    $this->licenseRepository->getStatisticsByProduct(),
                'licenseTotals' =>
                    $this->licenseRepository->getTotalStatistics(),
            ]
        );
    }

	public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // the name visible to end users
            ->setTitle('Diversworld Lizenzverwaltung')
            ->setFaviconPath('favicon.svg')
            ->useEntityTranslations()
            ->setTranslationDomain('messages')
            // the domain used by default is 'messages'
            //->setTranslationDomain('my-custom-domain')

            // set this option if you prefer the page content to span the entire
            // browser width, instead of the default design which sets a max width
            //->renderContentMaximized()

            // set this option if you prefer the sidebar (which contains the main menu)
            // to be displayed as a narrow column instead of the default expanded design
            //->renderSidebarMinimized()

            // use this option to create a custom theme for the backend without
            // writing any CSS (all Theme options are optional; you can call only
            // the ones you need)
            ->setTheme(Theme::new()
                // the accent color of buttons, links, switches, etc. Use the
                // hexadecimal, rgb(), hsl() or oklch() formats (no alpha channel).
                // The color of the text/icons displayed on top of primary-colored
                // elements is computed automatically based on this color.
                // The optional 'dark' argument sets a different primary color for
                // the dark color scheme (if not set, the same color is used in both)
                ->primaryColor('#004d99') //, dark: 'oklch(0.6 0.2 150)')
                ->radius('0.5rem')
                ->spacing('md'))
                // the gray scale used by all neutral surfaces, borders and text
                // colors: 'neutral', 'stone' (warmer), 'zinc', 'gray' or 'slate'
                // (cooler). The default look uses 'slate' in the light color scheme
                // and 'neutral' in the dark one. The optional 'dark' argument sets a
                // different gray scale for the dark color scheme (if not set, the
                // same scale is used in both). Instead of magic strings, you can use
                // the constants of EasyCorp\Bundle\EasyAdminBundle\Config\Option\GrayScale
                //->grays('zinc', dark: 'stone'))

            // set this option if you want to enable locale switching in the dashboard.
            // to further customize the locale option, pass an instance of
            // EasyCorp\Bundle\EasyAdminBundle\Config\Locale
            ->setLocales([
                Locale::new('de', 'DE Deutsch', 'locale-flag locale-flag-de'), // locale without custom options
                Locale::new('en', 'GB English', 'locale-flag locale-flag-gb'), // custom label and icon
				Locale::new('fr', 'FR Franais', 'locale-flag locale-flag-fr') // custom label and icon
            ]);
    }

    public function configureAssets(): Assets
	{
		return parent::configureAssets()
			->addCssFile('css/admin.css');
	}
		
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard(
            'Übersicht',
            'fa fa-home'
        );

        yield MenuItem::linkTo(
            CustomerCrudController::class,
            //'Kunden',
            icon: 'fa fa-users'
        );

        yield MenuItem::linkTo(
            ProductCrudController::class,
            //'Module',
            icon: 'fa fa-cubes'
        );

        yield MenuItem::linkTo(
            LicenseCrudController::class,
            //'Lizenzen',
            icon: 'fa fa-key'
        );

        yield MenuItem::linkTo(
            ActivationCrudController::class,
            //'Installationen',
            icon: 'fa fa-globe'
        );

        yield MenuItem::linkTo(
            UserCrudController::class,
            //'Administratoren',
            icon: 'fa fa-user-shield'
        );

        yield MenuItem::linkToLogout(
            'Abmelden',
            icon:   'fa fa-sign-out'
        );
    }

	public function configureUserMenu(
		UserInterface $user
	): UserMenu {
		$name = $user->getUserIdentifier();

		if (
			$user instanceof User
			&& $user->getFullname() !== ''
		) {
			$name = $user->getFullname();
		}

		$userMenu = parent::configureUserMenu($user)
			->setName($name);

		if ($user instanceof User) {
			if ($user->getFullname() !== '') {
				$name = $user->getFullname();
			}

			$avatarUrl = $user->getProfileImageUrl();
		} else {
			$avatarUrl = '/images/Diversworld_Viking.png';
		}
		return $userMenu
			->setAvatarUrl($avatarUrl)
			->addMenuItems([
				MenuItem::linkToRoute(
					'Mein Profil',
					'fa fa-user',
					'dashboard_profile'
				),

				MenuItem::linkToRoute(
					'Kennwort ändern',
					'fa fa-lock',
					'dashboard_password'
				),
			]);
	}

    #[Route(
        '/admin/profil',
        name: 'dashboard_profile'
    )]
    public function profile(
        AdminUrlGenerator $adminUrlGenerator
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $url = $adminUrlGenerator
            ->setController(
                ProfileCrudController::class
            )
            ->setAction('edit')
            ->setEntityId(
                (string) $user->getId()
            )
            ->generateUrl();

        return $this->redirect($url);
    }

    #[Route(
        '/admin/kennwort',
        name: 'dashboard_password'
    )]
    public function changePassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(
            ChangePasswordType::class
        );

        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $newPassword = $form
                ->get('newPassword')
                ->getData();

            $hashedPassword =
                $passwordHasher->hashPassword(
                    $user,
                    $newPassword
                );

            $user->setPassword(
                $hashedPassword
            );

            $user->setUpdatedAt(
                new \DateTimeImmutable()
            );

            $entityManager->flush();

            $this->addFlash(
                'success',
                'Dein Kennwort wurde erfolgreich geändert.'
            );

            return $this->redirectToRoute(
                'dashboard_profile'
            );
        }

        return $this->render(
            'admin/change_password.html.twig',
            [
                'passwordForm' =>
                    $form->createView(),
            ]
        );
    }
}