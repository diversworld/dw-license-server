<?php

namespace App\Controller\Admin;

use App\Entity\User;
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

#[AdminDashboard(
    routePath: '/admin{_locale}',
    routeName: 'admin',
	routeOptions: [
        'requirements' => ['_locale' => 'de|en|fr'],
        'defaults' => ['_locale' => 'de'],
        'methods' => ['GET'],
    ],
)]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render(
            'admin/dashboard.html.twig'
        );
    }

	public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // the name visible to end users
            ->setTitle('Diversworld Lizenzverwaltung')
            // you can include HTML contents too (e.g. to link to an image)
            //->setTitle('<img src="..."> ACME <span class="text-small">Corp.</span>')

            // by default EasyAdmin displays a black square as its default favicon;
            // use this method to display a custom favicon: the given path is passed
            // "as is" to the Twig asset() function:
            // <link rel="shortcut icon" href="{{ asset('...') }}">
            ->setFaviconPath('favicon.svg')

            // the domain used by default is 'messages'
            //->setTranslationDomain('my-custom-domain')

            // there's no need to define the "text direction" explicitly because
            // its default value is inferred dynamically from the user locale
            ->setTextDirection('ltr')
            // instead of magic strings, you can use constants as the value of
            // this option: EasyCorp\Bundle\EasyAdminBundle\Config\Option\TextDirection::LTR

            // set this option if you prefer the page content to span the entire
            // browser width, instead of the default design which sets a max width
            ->renderContentMaximized()

            // set this option if you prefer the sidebar (which contains the main menu)
            // to be displayed as a narrow column instead of the default expanded design
            //->renderSidebarMinimized()

            // by default, users can select between a "light" and "dark" mode for the
            // backend interface. Call this method if you prefer to disable the "dark"
            // mode for any reason (e.g. if your interface customizations are not ready for it)
            //->disableDarkMode()

            // by default, the UI color scheme is 'auto', which means that the backend
            // will use the same mode (light/dark) as the operating system and will
            // change in sync when the OS mode changes.
            // Use this option to set which mode ('light', 'dark' or 'auto') users will see
            // by default in the backend (users can change it via the color scheme selector)
            ->setDefaultColorScheme('auto')
			
            // instead of magic strings, you can use constants as the value of
            // this option: EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme::DARK

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
                // the base border radius from which all border radius values of the
                // backend derive. Use a CSS length in 'px' or 'rem' units or one of
                // these presets: 'none', 'xs', 'sm', 'md' (the default look), 'lg', 'xl'
                // (the 'sm', 'md', 'lg' and 'xl' presets are also available as the
                // constants of EasyCorp\Bundle\EasyAdminBundle\Config\Option\Size)
                ->radius('0.5rem')
                // the base spacing unit from which all paddings, margins, gaps and
                // control sizes derive; it defines the density of the whole interface.
                // Use a CSS length in 'px' or 'rem' units or one of these presets:
                // 'xs', 'sm', 'md' (the default look), 'lg', 'xl' (the 'sm', 'md', 'lg'
                // and 'xl' presets are also available as the constants of
                // EasyCorp\Bundle\EasyAdminBundle\Config\Option\Size)
                ->spacing('md')
                // the gray scale used by all neutral surfaces, borders and text
                // colors: 'neutral', 'stone' (warmer), 'zinc', 'gray' or 'slate'
                // (cooler). The default look uses 'slate' in the light color scheme
                // and 'neutral' in the dark one. The optional 'dark' argument sets a
                // different gray scale for the dark color scheme (if not set, the
                // same scale is used in both). Instead of magic strings, you can use
                // the constants of EasyCorp\Bundle\EasyAdminBundle\Config\Option\GrayScale
                ->grays('zinc', dark: 'stone'))

            // by default, all backend URLs are generated as absolute URLs. If you
            // need to generate relative URLs instead, call this method
            ->generateRelativeUrls()

            // set this option if you want to enable locale switching in the dashboard.
            // IMPORTANT: this feature won't work unless you add the {_locale}
            // parameter in the admin dashboard URL (e.g. '/admin/{_locale}').
            // the name of each locale will be rendered in that locale
            // (in the following example you'll see: "English", "Polski")
            //->setLocales(['de', 'en'])
            // to customize the labels of locales, pass a key => value array
            // (e.g. to display flags; although it's not a recommended practice,
            // because many languages/locales are not associated with a single country)
            ->setLocales([
				'de' => 'DE Deutsch',
                'en' => 'GB English',
				'fr' => 'FR Français',
            ])
            // to further customize the locale option, pass an instance of
            // EasyCorp\Bundle\EasyAdminBundle\Config\Locale
            ->setLocales([
                Locale::new('de', 'Deutsch', 'far fa-language'), // locale without custom options
                Locale::new('en', 'English', 'far fa-language'), // custom label and icon
				Locale::new('fr', 'Franais', 'far fa-language') // custom label and icon
            ])
        ;
    }

		
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard(
            'Übersicht',
            'fa fa-home'
        );

        yield MenuItem::linkTo(
            CustomerCrudController::class,
            'Kunden',
            'fa fa-users'
        );

        yield MenuItem::linkTo(
            ProductCrudController::class,
            'Module',
            'fa fa-cubes'
        );

        yield MenuItem::linkTo(
            LicenseCrudController::class,
            'Lizenzen',
            'fa fa-key'
        );

        yield MenuItem::linkTo(
            ActivationCrudController::class,
            'Installationen',
            'fa fa-globe'
        );

        yield MenuItem::linkTo(
            UserCrudController::class,
            'Administratoren',
            'fa fa-user-shield'
        );

        yield MenuItem::linkToLogout(
            'Abmelden',
            'fa fa-sign-out'
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

        return parent::configureUserMenu($user)
            ->setName($name)
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