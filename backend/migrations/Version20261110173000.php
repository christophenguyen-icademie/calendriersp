<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compléments structurés de logement pour distinguer les appartements d’une adresse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adresse ADD batiment VARCHAR(80) DEFAULT NULL, ADD etage VARCHAR(80) DEFAULT NULL, ADD appartement VARCHAR(80) DEFAULT NULL, ADD porte VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adresse DROP batiment, DROP etage, DROP appartement, DROP porte');
    }
}
