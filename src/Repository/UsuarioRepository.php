<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Materia;
use App\Entity\Usuario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Usuario>
 */
class UsuarioRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Usuario::class);
    }

    /** Busca por correo o por usuario LDAP, que es lo que la persona escribe en el formulario de acceso. */
    public function buscarPorIdentificador(string $identificador): ?Usuario
    {
        $identificador = trim($identificador);
        if ('' === $identificador) {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('u.email = :email OR u.ldapUsuario = :ldap')
            ->setParameter('email', mb_strtolower($identificador))
            ->setParameter('ldap', $identificador)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Personal visible en el directorio público, ordenado por departamento y apellido.
     *
     * @return list<Usuario>
     */
    public function directorio(?Materia $materia = null): array
    {
        $consulta = $this->createQueryBuilder('u')
            ->leftJoin('u.departamento', 'd')->addSelect('d')
            ->leftJoin('u.materias', 'm')->addSelect('m')
            ->where('u.activo = true')
            ->andWhere('u.visibleEnDirectorio = true')
            ->orderBy('d.orden', 'ASC')
            ->addOrderBy('d.nombre', 'ASC')
            ->addOrderBy('u.apellidos', 'ASC')
            ->addOrderBy('u.nombre', 'ASC');

        if (null !== $materia) {
            $consulta->andWhere(':materia MEMBER OF u.materias')->setParameter('materia', $materia);
        }

        return $consulta->getQuery()->getResult();
    }

    /** @return list<Usuario> */
    public function listadoAdministracion(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.departamento', 'd')->addSelect('d')
            ->orderBy('u.activo', 'DESC')
            ->addOrderBy('u.apellidos', 'ASC')
            ->addOrderBy('u.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function contarAdministradoresActivos(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.activo = true')
            ->andWhere('u.rol = :rol')
            ->setParameter('rol', \App\Enum\Rol::Administrador)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Usuario) {
            throw new UnsupportedUserException(sprintf('Instancias de "%s" no soportadas.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
