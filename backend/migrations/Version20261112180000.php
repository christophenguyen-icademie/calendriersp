<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Conserver la date de visite saisie dans MobileSP, distincte de la dernière modification.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passage ADD date_passage DATE DEFAULT NULL');
        $this->addSql('UPDATE passage SET date_passage = updated_at::date');
        $this->addSql('ALTER TABLE passage ALTER date_passage SET NOT NULL');
    }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE passage DROP date_passage'); }
}
