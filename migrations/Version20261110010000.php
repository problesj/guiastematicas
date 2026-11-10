<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subpestañas, pestañas como enlace externo, etiqueta Nuevo y columnas por sección';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pestana ADD enlace VARCHAR(500) DEFAULT NULL, ADD nueva TINYINT DEFAULT 0 NOT NULL, ADD padre_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pestana ADD CONSTRAINT FK_FBCC8A98613CEC58 FOREIGN KEY (padre_id) REFERENCES pestana (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_FBCC8A98613CEC58 ON pestana (padre_id)');
        $this->addSql('ALTER TABLE seccion ADD columnas INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pestana DROP FOREIGN KEY FK_FBCC8A98613CEC58');
        $this->addSql('DROP INDEX IDX_FBCC8A98613CEC58 ON pestana');
        $this->addSql('ALTER TABLE pestana DROP enlace, DROP nueva, DROP padre_id');
        $this->addSql('ALTER TABLE seccion DROP columnas');
    }
}
