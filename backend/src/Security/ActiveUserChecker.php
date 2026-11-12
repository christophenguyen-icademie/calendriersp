<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\{UserCheckerInterface, UserInterface};

final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new DisabledException('Compte désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void {}
}
