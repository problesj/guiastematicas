<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Autor;
use App\Servicio\CatalogoPaises;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tablas de países, autores y editoriales. Los autores y editoriales que estaban escritos como texto en cada recurso
 * pasan a sus tablas (sin duplicar nombres) y quedan vinculados a sus recursos en el mismo orden.
 */
final class Version20261109060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Países, autores y editoriales en tablas propias';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pais (id INT AUTO_INCREMENT NOT NULL, codigo VARCHAR(2) NOT NULL, nombre VARCHAR(100) NOT NULL, nombre_en VARCHAR(100) DEFAULT NULL, UNIQUE INDEX uniq_pais_codigo (codigo), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql("CREATE TABLE autor (id INT AUTO_INCREMENT NOT NULL, nombres VARCHAR(120) DEFAULT '' NOT NULL, apellidos VARCHAR(150) NOT NULL, orcid VARCHAR(19) DEFAULT NULL, sexo VARCHAR(20) DEFAULT 'no_informado' NOT NULL, pais_id INT DEFAULT NULL, INDEX idx_autor_apellidos (apellidos, nombres), UNIQUE INDEX uniq_autor_orcid (orcid), INDEX IDX_31075EBAC604D5C6 (pais_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`");
        $this->addSql('CREATE TABLE editorial (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(150) NOT NULL, pais_id INT DEFAULT NULL, UNIQUE INDEX uniq_editorial_nombre (nombre), INDEX IDX_8F713754C604D5C6 (pais_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE recurso_autor (id INT AUTO_INCREMENT NOT NULL, posicion INT DEFAULT 0 NOT NULL, recurso_id INT NOT NULL, autor_id INT NOT NULL, UNIQUE INDEX uniq_recurso_autor (recurso_id, autor_id), INDEX IDX_37F27819E52B6C4E (recurso_id), INDEX IDX_37F2781914D45BBE (autor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE autor ADD CONSTRAINT FK_31075EBAC604D5C6 FOREIGN KEY (pais_id) REFERENCES pais (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE editorial ADD CONSTRAINT FK_8F713754C604D5C6 FOREIGN KEY (pais_id) REFERENCES pais (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recurso_autor ADD CONSTRAINT FK_37F27819E52B6C4E FOREIGN KEY (recurso_id) REFERENCES recurso (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurso_autor ADD CONSTRAINT FK_37F2781914D45BBE FOREIGN KEY (autor_id) REFERENCES autor (id) ON DELETE CASCADE');

        $valores = [];
        foreach (CatalogoPaises::PAISES as $codigo => [$nombre, $nombreEn]) {
            $valores[] = \sprintf('(%s, %s, %s)', $this->connection->quote($codigo), $this->connection->quote($nombre), $this->connection->quote($nombreEn));
        }
        $this->addSql('INSERT INTO pais (codigo, nombre, nombre_en) VALUES '.implode(', ', $valores));

        // Editoriales: un registro por nombre distinto (la intercalación ya ignora mayúsculas y tildes).
        $this->addSql('ALTER TABLE recurso ADD editorial_id INT DEFAULT NULL');
        $this->addSql("INSERT INTO editorial (nombre) SELECT MIN(TRIM(editorial)) FROM recurso WHERE editorial IS NOT NULL AND TRIM(editorial) <> '' GROUP BY TRIM(editorial)");
        $this->addSql('UPDATE recurso r JOIN editorial e ON e.nombre = TRIM(r.editorial) SET r.editorial_id = e.id');
        $this->addSql('ALTER TABLE recurso DROP editorial');
        $this->addSql('ALTER TABLE recurso ADD CONSTRAINT FK_B2BB3764BAF1A24D FOREIGN KEY (editorial_id) REFERENCES editorial (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B2BB3764BAF1A24D ON recurso (editorial_id)');
    }

    /** Autores: se separan en PHP ("Pérez, Ana; Soto, Luis") y después se borra la columna de texto. */
    public function postUp(Schema $schema): void
    {
        $ids = [];
        $filas = $this->connection->fetchAllAssociative("SELECT id, autores FROM recurso WHERE autores IS NOT NULL AND TRIM(autores) <> ''");
        foreach ($filas as $fila) {
            $posicion = 0;
            $vistos = [];
            foreach (preg_split('/\s*;\s*/u', (string) $fila['autores'], -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $texto) {
                [$apellidos, $nombres] = Autor::separarNombre($texto);
                if ('' === $apellidos) {
                    [$apellidos, $nombres] = [$nombres, ''];
                }
                if ('' === $apellidos) {
                    continue;
                }
                $apellidos = mb_substr($apellidos, 0, 150);
                $nombres = mb_substr($nombres, 0, 120);
                $clave = Autor::clave($apellidos, $nombres);
                if (!isset($ids[$clave])) {
                    $this->connection->insert('autor', ['apellidos' => $apellidos, 'nombres' => $nombres]);
                    $ids[$clave] = (int) $this->connection->lastInsertId();
                }
                if (isset($vistos[$ids[$clave]])) {
                    continue;
                }
                $vistos[$ids[$clave]] = true;
                $this->connection->insert('recurso_autor', ['recurso_id' => $fila['id'], 'autor_id' => $ids[$clave], 'posicion' => $posicion++]);
            }
        }
        $this->connection->executeStatement('ALTER TABLE recurso DROP autores');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurso ADD autores VARCHAR(500) DEFAULT NULL, ADD editorial VARCHAR(150) DEFAULT NULL');
        $this->addSql("UPDATE recurso r SET r.autores = (SELECT LEFT(GROUP_CONCAT(IF(a.nombres = '', a.apellidos, CONCAT(a.apellidos, ', ', a.nombres)) ORDER BY ra.posicion SEPARATOR '; '), 500) FROM recurso_autor ra JOIN autor a ON a.id = ra.autor_id WHERE ra.recurso_id = r.id)");
        $this->addSql('UPDATE recurso r JOIN editorial e ON e.id = r.editorial_id SET r.editorial = e.nombre');
        $this->addSql('ALTER TABLE recurso DROP FOREIGN KEY FK_B2BB3764BAF1A24D');
        $this->addSql('DROP INDEX IDX_B2BB3764BAF1A24D ON recurso');
        $this->addSql('ALTER TABLE recurso DROP editorial_id');
        $this->addSql('DROP TABLE recurso_autor');
        $this->addSql('DROP TABLE autor');
        $this->addSql('DROP TABLE editorial');
        $this->addSql('DROP TABLE pais');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
