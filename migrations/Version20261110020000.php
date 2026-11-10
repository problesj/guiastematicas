<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal: enlace para agendar una cita.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usuario ADD enlace_cita VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usuario DROP enlace_cita');
    }
}
