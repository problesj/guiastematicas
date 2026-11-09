<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guías: imagen de cabecera (banner) e ícono.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guia ADD banner VARCHAR(100) DEFAULT NULL, ADD icono VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guia DROP banner, DROP icono');
    }
}
