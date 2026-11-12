<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Matérialiser les campagnes existantes et protéger les suppressions liées aux tournées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE campagne (id UUID NOT NULL, amicale_id UUID NOT NULL, annee INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_campagne_amicale_annee ON campagne (amicale_id, annee)');
        $this->addSql('CREATE INDEX idx_campagne_amicale ON campagne (amicale_id)');
        $this->addSql('ALTER TABLE campagne ADD CONSTRAINT fk_campagne_amicale FOREIGN KEY (amicale_id) REFERENCES amicale (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("INSERT INTO campagne (id, amicale_id, annee) SELECT gen_random_uuid(), amicale_id, annee FROM (SELECT amicale_id, EXTRACT(YEAR FROM date_debut)::int AS annee FROM tournee UNION SELECT id, COALESCE((parametres->>'campagne')::int, EXTRACT(YEAR FROM CURRENT_DATE)::int) FROM amicale) annees");
        $this->addSql('ALTER TABLE tournee ADD campagne_id UUID DEFAULT NULL');
        $this->addSql('UPDATE tournee t SET campagne_id = c.id FROM campagne c WHERE c.amicale_id = t.amicale_id AND c.annee = EXTRACT(YEAR FROM t.date_debut)::int');
        $this->addSql('ALTER TABLE tournee ALTER campagne_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_tournee_campagne ON tournee (campagne_id)');
        $this->addSql('ALTER TABLE tournee ADD CONSTRAINT fk_tournee_campagne FOREIGN KEY (campagne_id) REFERENCES campagne (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE passage DROP CONSTRAINT FK_2B258F67F661D013');
        $this->addSql('ALTER TABLE passage ADD CONSTRAINT FK_2B258F67F661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee_affectation DROP CONSTRAINT FK_F79C7DDBF661D013');
        $this->addSql('ALTER TABLE tournee_affectation ADD CONSTRAINT FK_F79C7DDBF661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passage DROP CONSTRAINT FK_2B258F67F661D013');
        $this->addSql('ALTER TABLE passage ADD CONSTRAINT FK_2B258F67F661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee_affectation DROP CONSTRAINT FK_F79C7DDBF661D013');
        $this->addSql('ALTER TABLE tournee_affectation ADD CONSTRAINT FK_F79C7DDBF661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee DROP CONSTRAINT fk_tournee_campagne');
        $this->addSql('DROP INDEX idx_tournee_campagne');
        $this->addSql('ALTER TABLE tournee DROP campagne_id');
        $this->addSql('DROP TABLE campagne');
    }
}
