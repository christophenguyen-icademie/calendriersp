<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110135925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajouter les carnets, les reçus numérotés et leur consommation unique par passage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE carnet (id UUID NOT NULL, numero VARCHAR(40) NOT NULL, premier_recu INT NOT NULL, nombre_recus INT NOT NULL, amicale_id UUID NOT NULL, tournee_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_carnet_numero_amicale ON carnet (amicale_id, numero)');
        $this->addSql('CREATE INDEX IDX_576D2650FEBD183 ON carnet (amicale_id)');
        $this->addSql('CREATE INDEX IDX_576D2650F661D013 ON carnet (tournee_id)');
        $this->addSql('CREATE TABLE recu (id UUID NOT NULL, numero INT NOT NULL, utilise_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, amicale_id UUID NOT NULL, carnet_id UUID NOT NULL, passage_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_recu_numero_amicale ON recu (amicale_id, numero)');
        $this->addSql('CREATE INDEX IDX_C0D10317FEBD183 ON recu (amicale_id)');
        $this->addSql('CREATE INDEX IDX_C0D10317FA207516 ON recu (carnet_id)');
        $this->addSql('CREATE INDEX IDX_C0D10317DCC6487D ON recu (passage_id)');
        $this->addSql('ALTER TABLE carnet ADD CONSTRAINT FK_576D2650FEBD183 FOREIGN KEY (amicale_id) REFERENCES amicale (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE carnet ADD CONSTRAINT FK_576D2650F661D013 FOREIGN KEY (tournee_id) REFERENCES tournee (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recu ADD CONSTRAINT FK_C0D10317FEBD183 FOREIGN KEY (amicale_id) REFERENCES amicale (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recu ADD CONSTRAINT FK_C0D10317FA207516 FOREIGN KEY (carnet_id) REFERENCES carnet (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recu ADD CONSTRAINT FK_C0D10317DCC6487D FOREIGN KEY (passage_id) REFERENCES passage (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE passage ADD recu_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE passage ADD CONSTRAINT FK_2B258F67A5D1C184 FOREIGN KEY (recu_id) REFERENCES recu (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2B258F67A5D1C184 ON passage (recu_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passage DROP CONSTRAINT FK_2B258F67A5D1C184');
        $this->addSql('DROP INDEX UNIQ_2B258F67A5D1C184');
        $this->addSql('ALTER TABLE passage DROP recu_id');
        $this->addSql('DROP TABLE recu');
        $this->addSql('DROP TABLE carnet');
    }
}
