<?php
namespace App\Security;
use App\Repository\ApiTokenRepository;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\{AuthenticationException, BadCredentialsException};
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\{Passport, SelfValidatingPassport};
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
final class ApiCredentialAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ApiTokenRepository $tokens) {}
    public function supports(Request $request): ?bool { return str_starts_with($request->headers->get('Authorization', ''), 'Bearer '); }
    public function authenticate(Request $request): Passport
    {
        $secret = substr($request->headers->get('Authorization', ''), 7);
        if (strlen($secret) < 16 || strlen($secret) > 512) { throw new BadCredentialsException(); }
        $rows = $this->tokens->findBy(['tokenHash' => hash('sha256', $secret), 'active' => true]);
        if (count($rows) !== 1) { throw new BadCredentialsException(); }
        $credential = $rows[0];
        if ($credential->isExpired() || !$credential->getCustomer()?->isActive()) { throw new BadCredentialsException(); }
        return new SelfValidatingPassport(new UserBadge((string) $credential->getId(), fn () => new ApiPrincipal($credential->getId(), $credential->getCustomer()->getId(), $credential->getScopes())));
    }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response { return null; }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response { return $this->start($request, $exception); }
    public function start(Request $request, ?AuthenticationException $authException = null): Response { return new JsonResponse(['error' => 'Invalid or missing API credential.'], 401, ['Cache-Control' => 'no-store']); }
}
