<?php

namespace App\Tests\Functional;

use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;

final class DialogTranslationTest extends IsolatedWebTestCase
{
    public static function languages(): iterable
    {
        yield 'German' => ['de', 'Vorname', 'Aktuelles Kennwort', 'Das aktuelle Kennwort ist nicht korrekt.', 'Willkommen zurück'];
        yield 'English' => ['en', 'First name', 'Current password', 'The current password is incorrect.', 'Welcome back'];
        yield 'French' => ['fr', 'Prénom', 'Mot de passe actuel', 'Le mot de passe actuel est incorrect.', 'Bon retour'];
        yield 'Spanish' => ['es', 'Nombre', 'Contraseña actual', 'La contraseña actual es incorrecta.', 'Bienvenido de nuevo'];
    }

    #[DataProvider('languages')]
    public function testDialogLabelsErrorsAndLocaleSurviveNavigation(string $locale, string $firstName, string $password, string $error, string $welcome): void
    {
        $license = $this->license();
        $user = $this->user();
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'correct-password-123'));
        $this->em->flush();
        $this->client->loginUser($user);
        $router = static::getContainer()->get('router');

        foreach (['admin_customer_new', 'admin_user_new'] as $route) {
            $this->client->request('GET', $router->generate($route, ['_locale' => $locale]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('label[for$="_firstname"]', $firstName);
            self::assertDoesNotMatchRegularExpression('/\b(?:ui|entitlements|user_creation|expiry_reminder|entities)\.[A-Za-z]/', $this->client->getCrawler()->filter('body')->text());
        }

        foreach (['admin_product_new', 'admin_license_plan_new', 'admin_license_from_plan'] as $route) {
            $this->client->request('GET', $router->generate($route, ['_locale' => $locale]));
            self::assertResponseIsSuccessful();
            self::assertDoesNotMatchRegularExpression('/\b(?:ui|entitlements|user_creation|expiry_reminder|entities)\.[A-Za-z]/', $this->client->getCrawler()->filter('body')->text());
        }

        $crawler = $this->client->request('GET', $router->generate('dashboard_password'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('label[for="change_password_currentPassword"]', $password);
        $form = $crawler->filter('form[name="change_password"]')->form();
        $this->client->submit($form, [
            'change_password[currentPassword]' => 'wrong-password',
            'change_password[newPassword][first]' => 'new-password-12345',
            'change_password[newPassword][second]' => 'new-password-12345',
        ]);
        self::assertSelectorTextContains('form', $error);
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', $welcome);
    }

    public function testCatalogsHaveMatchingKeysAndCoverReferencedUiMessages(): void
    {
        $project = static::getContainer()->getParameter('kernel.project_dir');
        $loader = new YamlFileLoader();
        foreach (['messages', 'validators'] as $domain) {
            $reference = $loader->load($project.'/translations/'.$domain.'.de.yaml', 'de', $domain);
            $expected = array_keys($reference->all($domain));
            sort($expected);
            foreach (['en', 'fr', 'es'] as $locale) {
                $catalog = $loader->load($project.'/translations/'.$domain.'.'.$locale.'.yaml', $locale, $domain);
                $actual = array_keys($catalog->all($domain));
                sort($actual);
                self::assertSame($expected, $actual, $locale.' '.$domain);
            }
        }

        $catalog = $loader->load($project.'/translations/messages.de.yaml', 'de');
        foreach (['src', 'templates'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project.'/'.$directory));
            foreach ($files as $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                preg_match_all('/[\'\"]((?:ui|entitlements|two_factor|license_action|license_status|archive|portal|expiry_reminder|user_creation|customer_scope|api_credentials|webhook|dashboard|role|policy|navigation|api_scope)\.[A-Za-z0-9_.]+)[\'\"]/', file_get_contents($file->getPathname()), $matches);
                foreach (array_unique($matches[1]) as $key) {
                    self::assertTrue($catalog->defines($key), $file->getPathname().': '.$key);
                }
            }
        }
    }
}
