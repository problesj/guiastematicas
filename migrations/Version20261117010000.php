<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sugerencias de recursos desde el sitio público, con su materia y su revisión.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sugerencia (id INT AUTO_INCREMENT NOT NULL, tipo VARCHAR(20) NOT NULL, titulo VARCHAR(255) NOT NULL, url VARCHAR(500) DEFAULT NULL, autores VARCHAR(255) DEFAULT NULL, identificador VARCHAR(30) DEFAULT NULL, descripcion LONGTEXT DEFAULT NULL, motivo LONGTEXT DEFAULT NULL, nombre VARCHAR(120) NOT NULL, correo VARCHAR(180) NOT NULL, idioma VARCHAR(2) DEFAULT \'es\' NOT NULL, estado VARCHAR(20) DEFAULT \'pendiente\' NOT NULL, motivo_rechazo LONGTEXT DEFAULT NULL, creada_en DATETIME NOT NULL, decidida_en DATETIME DEFAULT NULL, decidida_por_id INT DEFAULT NULL, recurso_id INT DEFAULT NULL, INDEX idx_sugerencia_estado (estado), INDEX IDX_4FBA3A095CF79891 (decidida_por_id), INDEX IDX_4FBA3A09E52B6C4E (recurso_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE sugerencia_materia (sugerencia_id INT NOT NULL, materia_id INT NOT NULL, INDEX IDX_6B16AD45678D7A1B (sugerencia_id), INDEX IDX_6B16AD45B54DBBCB (materia_id), PRIMARY KEY (sugerencia_id, materia_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE sugerencia ADD CONSTRAINT FK_4FBA3A095CF79891 FOREIGN KEY (decidida_por_id) REFERENCES usuario (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sugerencia ADD CONSTRAINT FK_4FBA3A09E52B6C4E FOREIGN KEY (recurso_id) REFERENCES recurso (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sugerencia_materia ADD CONSTRAINT FK_6B16AD45678D7A1B FOREIGN KEY (sugerencia_id) REFERENCES sugerencia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sugerencia_materia ADD CONSTRAINT FK_6B16AD45B54DBBCB FOREIGN KEY (materia_id) REFERENCES materia (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sugerencia_materia DROP FOREIGN KEY FK_6B16AD45678D7A1B');
        $this->addSql('ALTER TABLE sugerencia_materia DROP FOREIGN KEY FK_6B16AD45B54DBBCB');
        $this->addSql('ALTER TABLE sugerencia DROP FOREIGN KEY FK_4FBA3A095CF79891');
        $this->addSql('ALTER TABLE sugerencia DROP FOREIGN KEY FK_4FBA3A09E52B6C4E');
        $this->addSql('DROP TABLE sugerencia_materia');
        $this->addSql('DROP TABLE sugerencia');
    }
}
