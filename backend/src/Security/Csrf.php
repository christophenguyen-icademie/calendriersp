<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

final class Csrf
{
    public function token(Request $request): string
    {
        $session = $request->getSession();
        if (!$session->has('api_csrf')) {
            $session->set('api_csrf', bin2hex(random_bytes(32)));
        }

        return $session->get('api_csrf');
    }

    public function isValid(Request $request): bool
    {
        $expected = $request->getSession()->get('api_csrf');
        $actual = $request->headers->get('X-CSRF-Token');

        return is_string($expected) && is_string($actual) && hash_equals($expected, $actual);
    }
}
