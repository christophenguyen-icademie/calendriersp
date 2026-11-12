<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'tournee_affectation')]
#[ORM\UniqueConstraint(name: 'uniq_tournee_user', columns: ['tournee_id', 'user_id'])]
class TourneeAffectation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Tournee $tournee;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $roleEquipe = 'EQUIPIER';

    public function __construct(Tournee $tournee, User $user, string $roleEquipe = 'EQUIPIER')
    {
        if ($tournee->getAmicale()->getId()->toRfc4122() !== $user->getAmicale()->getId()->toRfc4122()) {
            throw new \DomainException('Utilisateur et tournée de deux amicales différentes.');
        }
        $this->id = Uuid::v7(); $this->tournee = $tournee; $this->user = $user; $this->roleEquipe = $roleEquipe;
    }

    public function getUser(): User { return $this->user; }
    public function getTournee(): Tournee { return $this->tournee; }
}
