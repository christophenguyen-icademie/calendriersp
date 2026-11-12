<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110142300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserver les polygones des secteurs et prévenir les doublons des adresses importées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adresse ADD source_id VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE adresse ADD identite VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_adresse_source_secteur ON adresse (secteur_id, source_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_adresse_identite_secteur ON adresse (secteur_id, identite)');
        $this->addSql('ALTER TABLE secteur ADD polygone JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_adresse_source_secteur');
        $this->addSql('DROP INDEX uniq_adresse_identite_secteur');
        $this->addSql('ALTER TABLE adresse DROP source_id');
        $this->addSql('ALTER TABLE adresse DROP identite');
        $this->addSql('ALTER TABLE secteur DROP polygone');
    }
}
