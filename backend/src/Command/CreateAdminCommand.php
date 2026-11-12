<?php

namespace App\Command;

use App\Api\Input;
use App\Entity\{Amicale, Campagne, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Créer une amicale et son administrateur, sans données de démonstration.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('identifiant', null, InputOption::VALUE_REQUIRED, 'Identifiant unique de connexion (4 à 20 lettres non accentuées ou chiffres).');
    }

    private function availableIdentifier(mixed $value): string
    {
        $identifier = Amicale::normaliserIdentifiant($value);
        if ($this->em->getRepository(Amicale::class)->findOneBy(['identifiant' => $identifier])) {
            throw new \InvalidArgumentException('Cet identifiant d’amicale est déjà utilisé. Choisissez-en un autre.');
        }
        return $identifier;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Lancez cette commande dans un terminal interactif pour saisir le mot de passe sans l’exposer.');
            return Command::FAILURE;
        }
        $identifier = $input->getOption('identifiant');
        if ($identifier !== null) {
            try { $identifier = $this->availableIdentifier($identifier); }
            catch (\InvalidArgumentException $error) { $io->error($error->getMessage()); return Command::INVALID; }
        }
        $nom = $io->ask('Nom de l’amicale', null, fn ($v) => Input::text(['nom' => $v], 'nom'));
        $identifier ??= $io->ask('Identifiant de connexion de l’amicale (4 à 20 lettres ou chiffres)', null, $this->availableIdentifier(...));
        $departement = $io->ask('Département', null, function ($v) {
            if (!is_string($v) || !preg_match('/^(?:\d{2,3}|2[AB])$/D', $v)) { throw new \InvalidArgumentException('Département invalide.'); }
            return $v;
        });
        $email = $io->ask('E-mail administrateur', null, function ($v) {
            if (!is_string($v) || strlen($v) > 180 || !filter_var($v, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('E-mail invalide.'); }
            return $v;
        });
        $password = $io->askHidden('Mot de passe (12 caractères minimum)', fn ($v) => Input::password(['password' => $v]));
        $amicale = new Amicale($nom, $departement, $identifier);
        $user = new User($amicale, $email);
        $user->setRoles(['ROLE_ADMIN_AMICALE'])->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($amicale);
        $this->em->persist(new Campagne($amicale, (int) date('Y')));
        $this->em->persist($user);
        try { $this->em->flush(); }
        catch (UniqueConstraintViolationException) {
            $io->error('Cet identifiant d’amicale vient d’être utilisé. Relancez la commande avec un autre identifiant.');
            return Command::FAILURE;
        }
        $io->success('Amicale et administrateur créés.');
        $io->writeln('Identifiant de l’amicale pour la connexion : '.$amicale->getIdentifiant());
        return Command::SUCCESS;
    }
}
