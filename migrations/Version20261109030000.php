<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 3: recursos de la lista A-Z (bases de datos) y sus materias.
 */
final class Version20261109030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 3: recursos A-Z';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurso (id INT AUTO_INCREMENT NOT NULL, titulo VARCHAR(200) NOT NULL, letra VARCHAR(1) NOT NULL, nombres_alternativos VARCHAR(255) DEFAULT NULL, url VARCHAR(1000) NOT NULL, requiere_proxy TINYINT DEFAULT 1 NOT NULL, descripcion LONGTEXT DEFAULT NULL, descripcion_en LONGTEXT DEFAULT NULL, proveedor VARCHAR(120) DEFAULT NULL, tipo VARCHAR(20) NOT NULL, acceso VARCHAR(20) NOT NULL, prueba_hasta DATE DEFAULT NULL, visible TINYINT DEFAULT 1 NOT NULL, clics INT DEFAULT 0 NOT NULL, creado_en DATETIME NOT NULL, actualizado_en DATETIME NOT NULL, INDEX idx_recurso_letra (letra), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recurso_materia (recurso_id INT NOT NULL, materia_id INT NOT NULL, INDEX IDX_3AD73EA6E52B6C4E (recurso_id), INDEX IDX_3AD73EA6B54DBBCB (materia_id), PRIMARY KEY (recurso_id, materia_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE recurso_materia ADD CONSTRAINT FK_3AD73EA6E52B6C4E FOREIGN KEY (recurso_id) REFERENCES recurso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurso_materia ADD CONSTRAINT FK_3AD73EA6B54DBBCB FOREIGN KEY (materia_id) REFERENCES materia (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso_materia DROP FOREIGN KEY FK_3AD73EA6E52B6C4E');
        $this->addSql('ALTER TABLE recurso_materia DROP FOREIGN KEY FK_3AD73EA6B54DBBCB');
        $this->addSql('DROP TABLE recurso_materia');
        $this->addSql('DROP TABLE recurso');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
