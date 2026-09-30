<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordRequestType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/kennwort-zuruecksetzen')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Schritt 1:
     * Benutzer gibt seine E-Mail-Adresse ein.
     */
    #[Route(
        '',
        name: 'app_forgot_password_request',
        methods: ['GET', 'POST']
    )]
    public function request(
        Request $request,
        UserRepository $userRepository,
        MailerInterface $mailer
    ): Response {
        $form = $this->createForm(
            ResetPasswordRequestType::class
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $this->logger->info(
                'Password reset requested.'
            );

            $email = strtolower(
                trim(
                    (string) $form
                        ->get('email')
                        ->getData()
                )
            );

            $user = $userRepository->findOneBy([
                'email' => $email,
            ]);

            /*
             * Absichtlich keine Information darüber
             * ausgeben, ob der Benutzer existiert.
             */
            if (!$user instanceof User) {

                $this->logger->warning(
                    'Password reset: user not found.'
                );

                return $this->redirectToRoute(
                    'app_check_email'
                );
            }

            $this->logger->info(
                'Password reset: user found.'
            );

            try {

                $resetToken = $this
                    ->resetPasswordHelper
                    ->generateResetToken($user);

                $this->logger->info(
                    'Password reset: token generated.'
                );

            } catch (
                ResetPasswordExceptionInterface $e
            ) {

                $this->logger->error(
                    'Password reset: token generation failed.',
                    [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                        'reason' => $e->getReason(),
                    ]
                );

                /*
                 * Auch bei einem Fehler keine Informationen
                 * über das Benutzerkonto preisgeben.
                 */
                return $this->redirectToRoute(
                    'app_check_email'
                );
            }

            /*
             * WICHTIG:
             * Diese Adresse an deine tatsächlich verwendete
             * SMTP-Absenderadresse anpassen.
             */
            $message = (new TemplatedEmail())
                ->from(
                    new Address(
                        'eckhard@diversworld.eu',
                        'Diversworld Lizenzverwaltung'
                    )
                )
                ->to($user->getEmail())
                ->subject(
                    'Kennwort zurücksetzen'
                )
                ->htmlTemplate(
                    'reset_password/email.html.twig'
                )
                ->context([
                    'resetToken' => $resetToken,
                    'user' => $user,
                ]);

            try {

                $this->logger->info(
                    'Password reset: sending email.'
                );

                $mailer->send($message);

                $this->logger->info(
                    'Password reset: email sent by Mailer.'
                );

            } catch (\Throwable $e) {

                $this->logger->error(
                    'Password reset: email sending failed.',
                    [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]
                );

                throw $e;
            }

            /*
             * TokenObject für die Informationsseite
             * in der Session hinterlegen.
             */
            $this->setTokenObjectInSession(
                $resetToken
            );

            return $this->redirectToRoute(
                'app_check_email'
            );
        }

        return $this->render(
            'reset_password/request.html.twig',
            [
                'requestForm' => $form->createView(),
            ]
        );
    }

    /**
     * Schritt 2:
     * Hinweis, dass der Benutzer seine E-Mails prüfen soll.
     */
    #[Route(
        '/pruefe-email',
        name: 'app_check_email',
        methods: ['GET']
    )]
    public function checkEmail(): Response
    {
        $resetToken = $this->getTokenObjectFromSession();

        return $this->render(
            'reset_password/check_email.html.twig',
            [
                'resetToken' => $resetToken,
            ]
        );
    }

    /**
     * Schritt 3:
     * Benutzer klickt auf den Link aus der E-Mail und
     * vergibt anschließend ein neues Kennwort.
     */
    #[Route(
        '/reset/{token}',
        name: 'app_reset_password',
        defaults: ['token' => null],
        methods: ['GET', 'POST']
    )]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        ?string $token = null
    ): Response {
        $this->logger->info(
            'Password reset page requested.',
            [
                'token_in_url' => $token !== null,
            ]
        );

        /*
         * Erster Aufruf:
         *
         * Der Token befindet sich in der URL aus der E-Mail.
         * Wir speichern ihn in der Session und entfernen ihn
         * anschließend durch einen Redirect aus der URL.
         */
        if ($token !== null) {

            $this->logger->info(
                'Password reset: storing token in session.'
            );

            $this->storeTokenInSession($token);

            return $this->redirectToRoute(
                'app_reset_password'
            );
        }

        /*
         * Zweiter Aufruf:
         *
         * Die URL enthält jetzt keinen Token mehr.
         * Er wird aus der Session gelesen.
         */
        $token = $this->getTokenFromSession();

        if ($token === null) {

            $this->logger->warning(
                'Password reset: no token found in session.'
            );

            $this->addFlash(
                'danger',
                'Der Link zum Zurücksetzen des Kennworts ist ungültig.'
            );

            return $this->redirectToRoute(
                'app_forgot_password_request'
            );
        }

        /*
         * Token überprüfen und zugehörigen Benutzer laden.
         */
        try {

            $user = $this
                ->resetPasswordHelper
                ->validateTokenAndFetchUser($token);

            $this->logger->info(
                'Password reset: token successfully validated.'
            );

        } catch (
            ResetPasswordExceptionInterface $e
        ) {

            $this->logger->warning(
                'Password reset: token validation failed.',
                [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'reason' => $e->getReason(),
                ]
            );

            /*
             * Ungültigen Token aus der Session entfernen.
             */
            $this->cleanSessionAfterReset();

            $this->addFlash(
                'danger',
                'Der Link zum Zurücksetzen des Kennworts ist ungültig oder abgelaufen.'
            );

            return $this->redirectToRoute(
                'app_forgot_password_request'
            );
        }

        if (!$user instanceof User) {

            $this->logger->error(
                'Password reset: returned object is not a User.'
            );

            throw $this->createAccessDeniedException();
        }

        /*
         * Formular für das neue Kennwort erzeugen.
         */
        $form = $this->createForm(
            ResetPasswordType::class
        );

        $form->handleRequest($request);

        /*
         * Neues Kennwort speichern.
         */
        if ($form->isSubmitted() && $form->isValid()) {

            $plainPassword = $form
                ->get('plainPassword')
                ->getData();

            $hashedPassword =
                $passwordHasher->hashPassword(
                    $user,
                    $plainPassword
                );

            $user->setPassword(
                $hashedPassword
            );

            $user->setUpdatedAt(
                new \DateTimeImmutable()
            );

            /*
             * Reset-Anforderung löschen.
             * Dadurch kann derselbe Link nicht erneut
             * verwendet werden.
             */
            $this->resetPasswordHelper
                ->removeResetRequest($token);

            $this->entityManager->flush();

            /*
             * Token aus der Session entfernen.
             */
            $this->cleanSessionAfterReset();

            $this->logger->info(
                'Password reset completed successfully.'
            );

            $this->addFlash(
                'success',
                'Dein Kennwort wurde erfolgreich geändert. Du kannst dich jetzt anmelden.'
            );

            return $this->redirectToRoute(
                'app_login'
            );
        }

        $this->logger->info(
            'Password reset: rendering new password form.'
        );

        return $this->render(
            'reset_password/reset.html.twig',
            [
                'resetForm' => $form->createView(),
            ]
        );
    }
}