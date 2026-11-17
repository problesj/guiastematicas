<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consultas a bibliotecarios desde su ficha pública y sus mensajes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consulta (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(120) NOT NULL, correo VARCHAR(180) NOT NULL, asunto VARCHAR(200) NOT NULL, token VARCHAR(64) NOT NULL, idioma VARCHAR(2) DEFAULT \'es\' NOT NULL, pendiente TINYINT DEFAULT 1 NOT NULL, cerrada TINYINT DEFAULT 0 NOT NULL, creada_en DATETIME NOT NULL, actualizada_en DATETIME NOT NULL, bibliotecario_id INT NOT NULL, UNIQUE INDEX UNIQ_A6FE3FDE5F37A13B (token), INDEX idx_consulta_pendiente (pendiente, cerrada), INDEX IDX_A6FE3FDE5B45EEFA (bibliotecario_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE mensaje_consulta (id INT AUTO_INCREMENT NOT NULL, texto LONGTEXT NOT NULL, de_personal TINYINT DEFAULT 0 NOT NULL, enviado_en DATETIME NOT NULL, correo_enviado TINYINT DEFAULT 0 NOT NULL, consulta_id INT NOT NULL, autor_id INT DEFAULT NULL, INDEX IDX_B504FAFFE38D288B (consulta_id), INDEX IDX_B504FAFF14D45BBE (autor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE consulta ADD CONSTRAINT FK_A6FE3FDE5B45EEFA FOREIGN KEY (bibliotecario_id) REFERENCES usuario (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mensaje_consulta ADD CONSTRAINT FK_B504FAFFE38D288B FOREIGN KEY (consulta_id) REFERENCES consulta (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mensaje_consulta ADD CONSTRAINT FK_B504FAFF14D45BBE FOREIGN KEY (autor_id) REFERENCES usuario (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mensaje_consulta DROP FOREIGN KEY FK_B504FAFFE38D288B');
        $this->addSql('ALTER TABLE mensaje_consulta DROP FOREIGN KEY FK_B504FAFF14D45BBE');
        $this->addSql('ALTER TABLE consulta DROP FOREIGN KEY FK_A6FE3FDE5B45EEFA');
        $this->addSql('DROP TABLE mensaje_consulta');
        $this->addSql('DROP TABLE consulta');
    }
}
