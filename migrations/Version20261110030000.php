<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guías: visibilidad (pública o restringida) y lectores asignados.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guia_lector (guia_id INT NOT NULL, usuario_id INT NOT NULL, INDEX IDX_33F5BA0A62AA81F (guia_id), INDEX IDX_33F5BA0ADB38439E (usuario_id), PRIMARY KEY (guia_id, usuario_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE guia_lector ADD CONSTRAINT FK_33F5BA0A62AA81F FOREIGN KEY (guia_id) REFERENCES guia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guia_lector ADD CONSTRAINT FK_33F5BA0ADB38439E FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guia ADD visibilidad VARCHAR(20) DEFAULT \'publica\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guia_lector DROP FOREIGN KEY FK_33F5BA0A62AA81F');
        $this->addSql('ALTER TABLE guia_lector DROP FOREIGN KEY FK_33F5BA0ADB38439E');
        $this->addSql('DROP TABLE guia_lector');
        $this->addSql('ALTER TABLE guia DROP visibilidad');
    }
}
