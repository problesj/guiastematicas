<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subguías: cada guía puede tener una guía principal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guia ADD principal_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE guia ADD CONSTRAINT FK_5B053B3D474870EE FOREIGN KEY (principal_id) REFERENCES guia (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5B053B3D474870EE ON guia (principal_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guia DROP FOREIGN KEY FK_5B053B3D474870EE');
        $this->addSql('DROP INDEX IDX_5B053B3D474870EE ON guia');
        $this->addSql('ALTER TABLE guia DROP principal_id');
    }
}
