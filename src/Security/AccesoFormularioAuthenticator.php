<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Usuario;
use App\Enum\Rol;
use App\Repository\UsuarioRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Formulario único de acceso para el personal. La persona escribe su correo o su usuario LDAP y la contraseña;
 * se acepta si coincide con su contraseña propia o, si tiene usuario LDAP, si el LDAP de la UCN la valida.
 */
final class AccesoFormularioAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const string RUTA = 'acceso';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UsuarioRepository $usuarios,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly VerificadorLdapInterface $ldap,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $datos = $request->getPayload();
        $identificador = trim($datos->getString('identificador'));
        $contrasena = $datos->getString('contrasena');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $identificador);

        return new Passport(
            new UserBadge($identificador, function (string $identificador): Usuario {
                return $this->usuarios->buscarPorIdentificador($identificador)
                    ?? throw new BadCredentialsException('Usuario no encontrado.');
            }),
            new CustomCredentials(fn (string $contrasena, Usuario $usuario): bool => $this->verificar($usuario, $contrasena), $contrasena),
            [new CsrfTokenBadge('acceso', $datos->getString('_csrf_token'))],
        );
    }

    private function verificar(Usuario $usuario, string $contrasena): bool
    {
        if ('' === $contrasena) {
            return false;
        }

        if ($usuario->tieneContrasenaPropia() && $this->hasher->isPasswordValid($usuario, $contrasena)) {
            if ($this->hasher->needsRehash($usuario)) {
                $usuario->setPassword($this->hasher->hashPassword($usuario, $contrasena));
            }

            return true;
        }

        $ldapUsuario = $usuario->getLdapUsuario();

        return null !== $ldapUsuario && $this->ldap->verificar($ldapUsuario, $contrasena);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $destino = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($destino ?? $this->urlGenerator->generate(
            // Un lector no entra al panel: va a la lista de guías (verá las restringidas que tenga asignadas).
            \in_array(Rol::Lector->value, $token->getRoleNames(), true) ? 'guias' : 'panel',
            ['_locale' => $request->getLocale()],
        ));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::RUTA, ['_locale' => $request->getLocale()]);
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && self::RUTA === $request->attributes->get('_route');
    }
}
