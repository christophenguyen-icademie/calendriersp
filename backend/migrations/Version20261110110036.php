<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110110036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Créer les tables métier de CalendrierSP, sans modifier les extensions PostGIS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE adresse (id UUID NOT NULL, ligne1 VARCHAR(180) NOT NULL, complement VARCHAR(180) DEFAULT NULL, code_postal VARCHAR(10) NOT NULL, ville VARCHAR(120) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, consigne TEXT DEFAULT NULL, secteur_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C35F08169F7E4405 ON adresse (secteur_id)');
        $this->addSql('CREATE TABLE amicale (id UUID NOT NULL, nom VARCHAR(180) NOT NULL, departement VARCHAR(3) NOT NULL, parametres JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amicale_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email_per_tenant ON app_user (amicale_id, email)');
        $this->addSql('CREATE INDEX IDX_88BDF3E9FEBD183 ON app_user (amicale_id)');
        $this->addSql('CREATE TABLE passage (id UUID NOT NULL, statut VARCHAR(255) NOT NULL, nb_calendriers INT NOT NULL, montant NUMERIC(9, 2) NOT NULL, moyen_paiement VARCHAR(255) DEFAULT NULL, commentaire TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tournee_id UUID NOT NULL, adresse_id UUID NOT NULL, saisi_par_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_passage_tournee_adresse ON passage (tournee_id, adresse_id)');
        $this->addSql('CREATE INDEX IDX_2B258F67F661D013 ON passage (tournee_id)');
        $this->addSql('CREATE INDEX IDX_2B258F674DE7DC5C ON passage (adresse_id)');
        $this->addSql('CREATE INDEX IDX_2B258F67B0F809FE ON passage (saisi_par_id)');
        $this->addSql('CREATE TABLE secteur (id UUID NOT NULL, nom VARCHAR(120) NOT NULL, type VARCHAR(30) NOT NULL, amicale_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8045251FFEBD183 ON secteur (amicale_id)');
        $this->addSql('CREATE TABLE tournee (id UUID NOT NULL, nom VARCHAR(140) NOT NULL, statut VARCHAR(255) NOT NULL, date_debut DATE NOT NULL, date_fin DATE DEFAULT NULL, stock_calendriers INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amicale_id UUID NOT NULL, secteur_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EBF67D7EFEBD183 ON tournee (amicale_id)');
        $this->addSql('CREATE INDEX IDX_EBF67D7E9F7E4405 ON tournee (secteur_id)');
        $this->addSql('CREATE TABLE tournee_affectation (id UUID NOT NULL, role_equipe VARCHAR(20) NOT NULL, tournee_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tournee_user ON tournee_affectation (tournee_id, user_id)');
        $this->addSql('CREATE INDEX IDX_F79C7DDBF661D013 ON tournee_affectation (tournee_id)');
        $this->addSql('CREATE INDEX IDX_F79C7DDBA76ED395 ON tournee_affectation (user_id)');
        $this->addSql('ALTER TABLE adresse ADD CONSTRAINT FK_C35F08169F7E4405 FOREIGN KEY (secteur_id) REFERENCES secteur (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9FEBD183 FOREIGN KEY (amicale_id) REFERENCES amicale (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE passage ADD CONSTRAINT FK_2B258F67F661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE passage ADD CONSTRAINT FK_2B258F674DE7DC5C FOREIGN KEY (adresse_id) REFERENCES adresse (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE passage ADD CONSTRAINT FK_2B258F67B0F809FE FOREIGN KEY (saisi_par_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE secteur ADD CONSTRAINT FK_8045251FFEBD183 FOREIGN KEY (amicale_id) REFERENCES amicale (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee ADD CONSTRAINT FK_EBF67D7EFEBD183 FOREIGN KEY (amicale_id) REFERENCES amicale (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee ADD CONSTRAINT FK_EBF67D7E9F7E4405 FOREIGN KEY (secteur_id) REFERENCES secteur (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee_affectation ADD CONSTRAINT FK_F79C7DDBF661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tournee_affectation ADD CONSTRAINT FK_F79C7DDBA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adresse DROP CONSTRAINT FK_C35F08169F7E4405');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E9FEBD183');
        $this->addSql('ALTER TABLE passage DROP CONSTRAINT FK_2B258F67F661D013');
        $this->addSql('ALTER TABLE passage DROP CONSTRAINT FK_2B258F674DE7DC5C');
        $this->addSql('ALTER TABLE passage DROP CONSTRAINT FK_2B258F67B0F809FE');
        $this->addSql('ALTER TABLE secteur DROP CONSTRAINT FK_8045251FFEBD183');
        $this->addSql('ALTER TABLE tournee DROP CONSTRAINT FK_EBF67D7EFEBD183');
        $this->addSql('ALTER TABLE tournee DROP CONSTRAINT FK_EBF67D7E9F7E4405');
        $this->addSql('ALTER TABLE tournee_affectation DROP CONSTRAINT FK_F79C7DDBF661D013');
        $this->addSql('ALTER TABLE tournee_affectation DROP CONSTRAINT FK_F79C7DDBA76ED395');
        $this->addSql('DROP TABLE adresse');
        $this->addSql('DROP TABLE amicale');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE passage');
        $this->addSql('DROP TABLE secteur');
        $this->addSql('DROP TABLE tournee');
        $this->addSql('DROP TABLE tournee_affectation');
    }
}
