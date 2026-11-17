<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Formato (impreso, electrónico o ambos) de libros y revistas, en recursos y sugerencias.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso ADD formato VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE sugerencia ADD formato VARCHAR(20) DEFAULT NULL');
        // Hasta ahora libros y revistas se presentaban como electrónicos. Excepción: los libros que entraron desde un
        // bloque Libros solo con su registro del catálogo (sin enlace propio, DOI ni e-ISBN), que son los impresos.
        $this->addSql("UPDATE recurso SET formato = 'electronico' WHERE tipo IN ('libros', 'revistas')");
        $this->addSql("UPDATE recurso SET formato = 'impreso' WHERE tipo = 'libros' AND requiere_proxy = 0 AND eisbn IS NULL AND doi IS NULL AND url LIKE '%/discovery/%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso DROP formato');
        $this->addSql('ALTER TABLE sugerencia DROP formato');
    }
}
