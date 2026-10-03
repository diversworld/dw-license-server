<?php
namespace App\Controller\Admin;
use App\Entity\{ApiToken, Customer, WebhookEndpoint, WebhookDelivery};
use App\Service\{ApiCredentials, WebhookRegistration, WebhookOutbox};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{TextType, ChoiceType, DateTimeType, UrlType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;
#[\Symfony\Component\HttpKernel\Attribute\RateLimit('two_factor')]
final class ApiIntegrationController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TranslatorInterface $translator) {}
    #[Route('/admin/{_locale}/customers/{id}/api-credentials', name: 'admin_api_credentials', requirements: ['_locale' => 'de|en|fr|es'], methods: ['GET', 'POST'])]
    public function credentials(Customer $customer, Request $request, ApiCredentials $credentials): Response
    {
        $this->denyAccessUnlessGranted('API_CREDENTIAL_MANAGE', $customer);
        $form = $this->createFormBuilder()->add('name', TextType::class, ['label' => 'api_credentials.name', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)]])->add('scopes', ChoiceType::class, ['label' => 'api_credentials.scopes', 'choices' => array_combine(ApiCredentials::SCOPES, ApiCredentials::SCOPES), 'multiple' => true, 'constraints' => [new Assert\Count(min: 1)]])->add('expiry', DateTimeType::class, ['label' => 'portal.expiry', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull()]])->getForm()->handleRequest($request);
        $secret = null;
        if ($form->isSubmitted() && $form->isValid()) { try { $created = $credentials->create($customer, $form->get('name')->getData(), $form->get('scopes')->getData(), $form->get('expiry')->getData()); $secret = $created['secret']; } catch (\DomainException $error) { $form->addError(new FormError($this->translator->trans($error->getMessage()))); } }
        return $this->render('admin/integration.html.twig', ['customer' => $customer, 'form' => $form, 'secret' => $secret, 'kind' => 'api', 'credentials' => $this->em->getRepository(ApiToken::class)->findBy(['customer' => $customer]), 'endpoints' => [], 'deliveries' => []], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200, headers: ['Cache-Control' => 'no-store']));
    }
    #[Route('/admin/{_locale}/api-credentials/{id}/revoke', name: 'admin_api_credential_revoke', methods: ['POST'])]
    public function revoke(ApiToken $credential, Request $request, ApiCredentials $credentials): Response
    {
        $this->denyAccessUnlessGranted('API_CREDENTIAL_MANAGE', $credential->getCustomer());
        if (!$this->isCsrfTokenValid('api_revoke_'.$credential->getId(), $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $credentials->revoke($credential); return $this->redirectToRoute('admin_api_credentials', ['id' => (string) $credential->getCustomer()->getId(), '_locale' => $request->getLocale()]);
    }
    #[Route('/admin/{_locale}/customers/{id}/webhooks', name: 'admin_webhooks', requirements: ['_locale' => 'de|en|fr|es'], methods: ['GET', 'POST'])]
    public function webhooks(Customer $customer, Request $request, WebhookRegistration $registration): Response
    {
        $this->denyAccessUnlessGranted('API_CREDENTIAL_MANAGE', $customer);
        $form = $this->createFormBuilder()->add('url', UrlType::class, ['label' => 'webhook.url', 'constraints' => [new Assert\NotBlank(), new Assert\Url(protocols: ['https'])]])->add('events', ChoiceType::class, ['label' => 'webhook.events', 'choices' => array_combine(WebhookOutbox::EVENTS, WebhookOutbox::EVENTS), 'multiple' => true, 'constraints' => [new Assert\Count(min: 1)]])->getForm()->handleRequest($request);
        $secret = null;
        if ($form->isSubmitted() && $form->isValid()) { try { $created = $registration->create($customer, $form->get('url')->getData(), $form->get('events')->getData()); $secret = $created['secret']; } catch (\DomainException $error) { $form->addError(new FormError($this->translator->trans($error->getMessage()))); } }
        $endpoints = $this->em->getRepository(WebhookEndpoint::class)->findBy(['customer' => $customer]);
        return $this->render('admin/integration.html.twig', ['customer' => $customer, 'form' => $form, 'secret' => $secret, 'kind' => 'webhook', 'credentials' => [], 'endpoints' => $endpoints, 'deliveries' => $endpoints === [] ? [] : $this->em->getRepository(WebhookDelivery::class)->findBy(['endpoint' => $endpoints])], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200, headers: ['Cache-Control' => 'no-store']));
    }
    #[Route('/admin/{_locale}/webhooks/{id}/disable', name: 'admin_webhook_disable', methods: ['POST'])]
    public function disable(WebhookEndpoint $endpoint, Request $request, WebhookRegistration $registration): Response
    {
        $this->denyAccessUnlessGranted('API_CREDENTIAL_MANAGE', $endpoint->getCustomer());
        if (!$this->isCsrfTokenValid('webhook_disable_'.$endpoint->getId(), $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $registration->disable($endpoint); return $this->redirectToRoute('admin_webhooks', ['id' => (string) $endpoint->getCustomer()->getId(), '_locale' => $request->getLocale()]);
    }
}
