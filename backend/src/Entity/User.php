<?php
namespace App\Entity;

use App\Entity\Contract\AmicaleAwareInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email_per_tenant', columns: ['amicale_id', 'email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, AmicaleAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Amicale $amicale;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_SAPEUR'];

    #[ORM\Column]
    private string $password;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Amicale $amicale, string $email)
    {
        $this->id = Uuid::v7();
        $this->amicale = $amicale;
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getAmicale(): Amicale { return $this->amicale; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { return array_values(array_unique([...$this->roles, 'ROLE_USER'])); }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): self { $this->isActive = $active; return $this; }
}
