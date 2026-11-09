<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recursos bibliográficos: datos propios de cada tipo (autores, editorial, año, ISBN, ISSN, disponibilidad, normas).
 */
final class Version20261109040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Datos bibliográficos por tipo de recurso';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso ADD autores VARCHAR(500) DEFAULT NULL, ADD editorial VARCHAR(150) DEFAULT NULL, ADD isbn VARCHAR(20) DEFAULT NULL, ADD eisbn VARCHAR(20) DEFAULT NULL, ADD anio SMALLINT DEFAULT NULL, ADD issn VARCHAR(9) DEFAULT NULL, ADD eissn VARCHAR(9) DEFAULT NULL, ADD disponible_desde DATE DEFAULT NULL, ADD codigo_norma VARCHAR(60) DEFAULT NULL, ADD organismo VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso DROP autores, DROP editorial, DROP isbn, DROP eisbn, DROP anio, DROP issn, DROP eissn, DROP disponible_desde, DROP codigo_norma, DROP organismo');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
