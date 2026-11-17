<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bloques y pestañas reutilizables entre guías (vínculos al original) y guías de contenido común.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guia ADD comun TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE bloque ADD compartible TINYINT DEFAULT 0 NOT NULL, ADD origen_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bloque ADD CONSTRAINT FK_F1DA68E893529ECD FOREIGN KEY (origen_id) REFERENCES bloque (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F1DA68E893529ECD ON bloque (origen_id)');
        $this->addSql('ALTER TABLE pestana ADD compartible TINYINT DEFAULT 0 NOT NULL, ADD origen_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pestana ADD CONSTRAINT FK_FBCC8A9893529ECD FOREIGN KEY (origen_id) REFERENCES pestana (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_FBCC8A9893529ECD ON pestana (origen_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloque DROP FOREIGN KEY FK_F1DA68E893529ECD');
        $this->addSql('DROP INDEX IDX_F1DA68E893529ECD ON bloque');
        $this->addSql('ALTER TABLE bloque DROP compartible, DROP origen_id');
        $this->addSql('ALTER TABLE pestana DROP FOREIGN KEY FK_FBCC8A9893529ECD');
        $this->addSql('DROP INDEX IDX_FBCC8A9893529ECD ON pestana');
        $this->addSql('ALTER TABLE pestana DROP compartible, DROP origen_id');
        $this->addSql('ALTER TABLE guia DROP comun');
    }
}
