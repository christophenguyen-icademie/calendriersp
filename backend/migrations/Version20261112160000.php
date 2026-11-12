<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identifiants de connexion alphanumériques des amicales, sans modifier leurs références existantes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE amicale ADD identifiant VARCHAR(20) DEFAULT NULL');
        $used = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, nom FROM amicale ORDER BY created_at, id') as $row) {
            $latin = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $row['nom']);
            $base = substr(preg_replace('/[^a-z0-9]/', '', $latin ?: ''), 0, 20);
            if (strlen($base) < 4) { $base = 'amicale'.$base; }
            $identifier = $base;
            for ($n = 2; isset($used[$identifier]); ++$n) {
                $identifier = substr($base, 0, 20 - strlen((string) $n)).$n;
            }
            $used[$identifier] = true;
            $this->addSql('UPDATE amicale SET identifiant = :identifiant WHERE id = :id', ['identifiant' => $identifier, 'id' => $row['id']]);
        }
        $this->addSql('ALTER TABLE amicale ALTER identifiant SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_amicale_identifiant ON amicale (identifiant)');
        $this->addSql("ALTER TABLE amicale ADD CONSTRAINT chk_amicale_identifiant CHECK (identifiant ~ '^[a-z0-9]{4,20}$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE amicale DROP identifiant');
    }
}
