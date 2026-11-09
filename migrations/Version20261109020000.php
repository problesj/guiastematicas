<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 2: personal (usuarios con rol y métodos de acceso), departamentos y materias.
 */
final class Version20261109020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 2: usuarios, departamentos y materias';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE materia (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(150) NOT NULL, nombre_en VARCHAR(150) DEFAULT NULL, slug VARCHAR(160) NOT NULL, codigo VARCHAR(30) DEFAULT NULL, activa TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_6DF05284989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE usuario (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, nombre VARCHAR(100) NOT NULL, apellidos VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL, rol VARCHAR(30) NOT NULL, password VARCHAR(255) DEFAULT NULL, ldap_usuario VARCHAR(100) DEFAULT NULL, google_id VARCHAR(64) DEFAULT NULL, activo TINYINT DEFAULT 1 NOT NULL, visible_en_directorio TINYINT DEFAULT 1 NOT NULL, cargo VARCHAR(120) DEFAULT NULL, cargo_en VARCHAR(120) DEFAULT NULL, telefono VARCHAR(40) DEFAULT NULL, ubicacion VARCHAR(120) DEFAULT NULL, sede VARCHAR(20) DEFAULT NULL, biografia LONGTEXT DEFAULT NULL, biografia_en LONGTEXT DEFAULT NULL, foto VARCHAR(80) DEFAULT NULL, creado_en DATETIME NOT NULL, ultimo_acceso DATETIME DEFAULT NULL, departamento_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2265B05DE7927C74 (email), UNIQUE INDEX UNIQ_2265B05D989D9B62 (slug), UNIQUE INDEX UNIQ_2265B05D4B09208E (ldap_usuario), UNIQUE INDEX UNIQ_2265B05D76F5C865 (google_id), INDEX IDX_2265B05D5A91C08D (departamento_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE usuario_materia (usuario_id INT NOT NULL, materia_id INT NOT NULL, INDEX IDX_14D6F8C3DB38439E (usuario_id), INDEX IDX_14D6F8C3B54DBBCB (materia_id), PRIMARY KEY (usuario_id, materia_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE departamento (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(120) NOT NULL, nombre_en VARCHAR(120) DEFAULT NULL, sede VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, telefono VARCHAR(40) DEFAULT NULL, orden INT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE usuario ADD CONSTRAINT FK_2265B05D5A91C08D FOREIGN KEY (departamento_id) REFERENCES departamento (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE usuario_materia ADD CONSTRAINT FK_14D6F8C3DB38439E FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE usuario_materia ADD CONSTRAINT FK_14D6F8C3B54DBBCB FOREIGN KEY (materia_id) REFERENCES materia (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usuario DROP FOREIGN KEY FK_2265B05D5A91C08D');
        $this->addSql('ALTER TABLE usuario_materia DROP FOREIGN KEY FK_14D6F8C3DB38439E');
        $this->addSql('ALTER TABLE usuario_materia DROP FOREIGN KEY FK_14D6F8C3B54DBBCB');
        $this->addSql('DROP TABLE usuario_materia');
        $this->addSql('DROP TABLE usuario');
        $this->addSql('DROP TABLE materia');
        $this->addSql('DROP TABLE departamento');
    }

    public function isTransactional(): bool
    {
        // MySQL confirma implícitamente las sentencias DDL.
        return false;
    }
}
