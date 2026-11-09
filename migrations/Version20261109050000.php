<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Artículos científicos: fuente (revista), volumen, fascículo, páginas y DOI.
 */
final class Version20261109050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Datos de artículos científicos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso ADD fuente VARCHAR(255) DEFAULT NULL, ADD volumen VARCHAR(20) DEFAULT NULL, ADD fasciculo VARCHAR(20) DEFAULT NULL, ADD paginas VARCHAR(30) DEFAULT NULL, ADD doi VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso DROP fuente, DROP volumen, DROP fasciculo, DROP paginas, DROP doi');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
