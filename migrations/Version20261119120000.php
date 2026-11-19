<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categorías ASJC (Scopus) asociadas a cada materia.';
    }

    public function up(Schema $schema): void
    {
        // MySQL no acepta un literal como valor por omisión de una columna JSON: se agrega, se completa y se exige.
        $this->addSql('ALTER TABLE materia ADD asjc JSON DEFAULT NULL');
        $this->addSql("UPDATE materia SET asjc = '[]'");
        $this->addSql('ALTER TABLE materia MODIFY asjc JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE materia DROP asjc');
    }
}
