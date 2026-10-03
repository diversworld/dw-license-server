<?php
namespace App\Controller\Admin;
use App\Entity\{Customer, License, LicensePlan};
use App\Service\ProductEntitlements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextareaType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;
#[IsGranted('ROLE_VIEWER')]
final class PlanIssueController extends AbstractController
{
    #[Route('/admin/{_locale}/licenses/from-plan', name: 'admin_license_from_plan', requirements: ['_locale' => 'de|en|fr|es'], methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em, ProductEntitlements $rights, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('LICENSE_CREATE');
        $form = $this->createFormBuilder()->add('customer', EntityType::class, ['label' => 'entitlements.customer', 'class' => Customer::class, 'constraints' => [new Assert\NotNull()]])->add('plan', EntityType::class, ['label' => 'entitlements.plan', 'class' => LicensePlan::class, 'query_builder' => fn ($repository) => $repository->createQueryBuilder('p')->where('p.active = true'), 'constraints' => [new Assert\NotNull()]])->add('mode', ChoiceType::class, ['label' => 'entitlements.mode', 'choices' => ['Online' => 'online', 'Offline' => 'offline']])->add('reason', TextareaType::class, ['label' => 'license_action.reason', 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 3, max: 1000)]])->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $customer = $form->get('customer')->getData(); $this->denyAccessUnlessGranted('LICENSE_CREATE', $customer);
            try {
                if (!$customer->isActive()) { throw new \DomainException('entitlements.invalid_plan'); }
                $license = (new License())->setCustomer($customer)->setMode($form->get('mode')->getData())->setNotes($form->get('reason')->getData());
                $em->getConnection()->transactional(function () use ($em, $rights, $license, $form): void {
                    $plan = $form->get('plan')->getData(); $em->refresh($license->getCustomer(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE); $em->refresh($plan->getProduct(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE); $em->refresh($plan, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    if (!$license->getCustomer()->isActive()) { throw new \DomainException('entitlements.invalid_plan'); }
                    $rights->applyPlan($license, $plan); $em->persist($license); $em->flush();
                });
                return $this->redirectToRoute('admin_license_history', ['id' => (string) $license->getId(), '_locale' => $request->getLocale()]);
            } catch (\DomainException $error) { $form->addError(new FormError($translator->trans($error->getMessage()))); }
        }
        return $this->render('admin/plan_issue.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200, headers: ['Cache-Control' => 'no-store']));
    }
}
