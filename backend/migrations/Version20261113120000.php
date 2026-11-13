<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Ajouter le nom des sapeurs sans modifier leur identifiant ni les comptes existants.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE app_user ADD nom VARCHAR(180) DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE app_user DROP nom'); }
}
