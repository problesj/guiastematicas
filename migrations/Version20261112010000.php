<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de integraciones definidos desde el panel (claves cifradas).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ajuste (clave VARCHAR(60) NOT NULL, valor LONGTEXT NOT NULL, cifrado TINYINT NOT NULL, actualizado_en DATETIME NOT NULL, PRIMARY KEY (clave)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ajuste');
    }
}
