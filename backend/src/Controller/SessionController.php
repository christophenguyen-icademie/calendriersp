<?php

namespace App\Controller;

use App\Api\Workspace;
use App\Security\Csrf;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SessionController
{
    #[Route('/api/session', methods: ['GET'])]
    public function session(Request $request, Csrf $csrf, Security $security, Workspace $workspace): JsonResponse
    {
        return new JsonResponse(['csrfToken' => $csrf->token($request), 'user' => $security->getUser() ? $workspace->profile() : null]);
    }

    #[Route('/api/login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('The authenticator handles this request.');
    }

    #[Route('/api/logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokens): JsonResponse
    {
        $tokens->setToken(null);
        $request->getSession()->invalidate();
        return new JsonResponse(['authenticated' => false]);
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(Workspace $workspace): JsonResponse
    {
        return new JsonResponse($workspace->profile());
    }
}
