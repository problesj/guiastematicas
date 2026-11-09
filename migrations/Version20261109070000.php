<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 4: guías con pestañas, secciones y bloques; coeditores y materias.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bloque (id INT AUTO_INCREMENT NOT NULL, tipo VARCHAR(20) NOT NULL, titulo VARCHAR(150) DEFAULT NULL, posicion INT NOT NULL, contenido JSON NOT NULL, seccion_id INT NOT NULL, INDEX IDX_F1DA68E87A5A413A (seccion_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE guia (id INT AUTO_INCREMENT NOT NULL, titulo VARCHAR(200) NOT NULL, slug VARCHAR(120) NOT NULL, descripcion VARCHAR(500) DEFAULT NULL, idioma VARCHAR(2) DEFAULT \'es\' NOT NULL, estado VARCHAR(20) NOT NULL, disposicion VARCHAR(20) NOT NULL, visitas INT NOT NULL, publicada_en DATETIME DEFAULT NULL, creada_en DATETIME NOT NULL, actualizada_en DATETIME NOT NULL, traduccion_id INT DEFAULT NULL, responsable_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_5B053B3D989D9B62 (slug), UNIQUE INDEX UNIQ_5B053B3DDC70F7AF (traduccion_id), INDEX idx_guia_estado (estado), INDEX IDX_5B053B3D53C59D72 (responsable_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE guia_coeditor (guia_id INT NOT NULL, usuario_id INT NOT NULL, INDEX IDX_26E3EE7962AA81F (guia_id), INDEX IDX_26E3EE79DB38439E (usuario_id), PRIMARY KEY (guia_id, usuario_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE guia_materia (guia_id INT NOT NULL, materia_id INT NOT NULL, INDEX IDX_B21A6B9462AA81F (guia_id), INDEX IDX_B21A6B94B54DBBCB (materia_id), PRIMARY KEY (guia_id, materia_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE pestana (id INT AUTO_INCREMENT NOT NULL, titulo VARCHAR(100) NOT NULL, slug VARCHAR(110) NOT NULL, posicion INT NOT NULL, guia_id INT NOT NULL, INDEX IDX_FBCC8A9862AA81F (guia_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE seccion (id INT AUTO_INCREMENT NOT NULL, titulo VARCHAR(150) DEFAULT NULL, posicion INT NOT NULL, pestana_id INT NOT NULL, INDEX IDX_E0BD15C9C6FFD2D9 (pestana_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE bloque ADD CONSTRAINT FK_F1DA68E87A5A413A FOREIGN KEY (seccion_id) REFERENCES seccion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guia ADD CONSTRAINT FK_5B053B3DDC70F7AF FOREIGN KEY (traduccion_id) REFERENCES guia (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE guia ADD CONSTRAINT FK_5B053B3D53C59D72 FOREIGN KEY (responsable_id) REFERENCES usuario (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE guia_coeditor ADD CONSTRAINT FK_26E3EE7962AA81F FOREIGN KEY (guia_id) REFERENCES guia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guia_coeditor ADD CONSTRAINT FK_26E3EE79DB38439E FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guia_materia ADD CONSTRAINT FK_B21A6B9462AA81F FOREIGN KEY (guia_id) REFERENCES guia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guia_materia ADD CONSTRAINT FK_B21A6B94B54DBBCB FOREIGN KEY (materia_id) REFERENCES materia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pestana ADD CONSTRAINT FK_FBCC8A9862AA81F FOREIGN KEY (guia_id) REFERENCES guia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seccion ADD CONSTRAINT FK_E0BD15C9C6FFD2D9 FOREIGN KEY (pestana_id) REFERENCES pestana (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloque DROP FOREIGN KEY FK_F1DA68E87A5A413A');
        $this->addSql('ALTER TABLE guia DROP FOREIGN KEY FK_5B053B3DDC70F7AF');
        $this->addSql('ALTER TABLE guia DROP FOREIGN KEY FK_5B053B3D53C59D72');
        $this->addSql('ALTER TABLE guia_coeditor DROP FOREIGN KEY FK_26E3EE7962AA81F');
        $this->addSql('ALTER TABLE guia_coeditor DROP FOREIGN KEY FK_26E3EE79DB38439E');
        $this->addSql('ALTER TABLE guia_materia DROP FOREIGN KEY FK_B21A6B9462AA81F');
        $this->addSql('ALTER TABLE guia_materia DROP FOREIGN KEY FK_B21A6B94B54DBBCB');
        $this->addSql('ALTER TABLE pestana DROP FOREIGN KEY FK_FBCC8A9862AA81F');
        $this->addSql('ALTER TABLE seccion DROP FOREIGN KEY FK_E0BD15C9C6FFD2D9');
        $this->addSql('DROP TABLE bloque');
        $this->addSql('DROP TABLE guia');
        $this->addSql('DROP TABLE guia_coeditor');
        $this->addSql('DROP TABLE guia_materia');
        $this->addSql('DROP TABLE pestana');
        $this->addSql('DROP TABLE seccion');
    }
}
