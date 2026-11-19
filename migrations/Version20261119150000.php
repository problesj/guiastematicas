<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Clasificacion\SincronizadorAsjc;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Materias base: una por categoría ASJC de Scopus (las existentes con el mismo nombre se reutilizan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE materia ADD codigo_asjc INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6DF05284DF02174E ON materia (codigo_asjc)');
    }

    public function postUp(Schema $schema): void
    {
        SincronizadorAsjc::sincronizar($this->connection);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE materia SET codigo_asjc = NULL');
        $this->addSql('DROP INDEX UNIQ_6DF05284DF02174E ON materia');
        $this->addSql('ALTER TABLE materia DROP codigo_asjc');
    }
}
