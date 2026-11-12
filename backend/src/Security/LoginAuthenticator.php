<?php

namespace App\Security;

use App\Entity\{Amicale, User};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\{AuthenticationException, CustomUserMessageAuthenticationException};
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class LoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/api/login' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $data = $request->toArray();
        $email = $data['email'] ?? null;
        $amicale = $data['amicaleId'] ?? null;
        $password = $data['password'] ?? null;
        if (!is_string($email) || !is_string($password) || strlen($password) > 4096) {
            throw new CustomUserMessageAuthenticationException('Identifiants invalides.');
        }
        try { $amicale = Amicale::normaliserIdentifiant($amicale); }
        catch (\InvalidArgumentException) { throw new CustomUserMessageAuthenticationException('Identifiants invalides.'); }
        $email = mb_strtolower(trim($email));

        return new Passport(new UserBadge($amicale.':'.$email, function () use ($amicale, $email): User {
            $user = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->join('u.amicale', 'a')
                ->where('a.identifiant = :amicale')->andWhere('u.email = :email')
                ->setParameter('amicale', $amicale)->setParameter('email', $email)->getQuery()->getOneOrNullResult();
            if (!$user) {
                throw new CustomUserMessageAuthenticationException('Identifiants invalides.');
            }
            return $user;
        }), new PasswordCredentials($password));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new JsonResponse(['authenticated' => true]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Identifiants invalides ou compte désactivé.', 'code' => 'INVALID_CREDENTIALS'], 401);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'Veuillez vous connecter.', 'code' => 'AUTHENTICATION_REQUIRED'], 401);
    }
}
