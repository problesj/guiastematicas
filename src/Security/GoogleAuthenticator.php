<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Usuario;
use App\Enum\Rol;
use App\Repository\UsuarioRepository;
use App\Servicio\MetodosAcceso;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Acceso con cuenta de Google. Solo entra quien ya está registrado en Guías temáticas con ese correo
 * (y, si se define GOOGLE_DOMINIO, solo correos de ese dominio). En el primer ingreso se guarda el id de Google.
 */
final class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    public const string RUTA_VERIFICAR = 'google_verificar';
    public const string IDIOMA_SESION = 'google_idioma';

    public function __construct(
        private readonly ClientRegistry $clientes,
        private readonly UsuarioRepository $usuarios,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MetodosAcceso $metodos,
        #[Autowire('%env(string:default::ajuste:GOOGLE_DOMINIO)%')]
        private readonly string $dominio = '',
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return self::RUTA_VERIFICAR === $request->attributes->get('_route') && $this->metodos->google();
    }

    public function authenticate(Request $request): Passport
    {
        $cliente = $this->clientes->getClient('google');
        $token = $this->fetchAccessToken($cliente);

        return new SelfValidatingPassport(new UserBadge($token->getToken(), function () use ($cliente, $token): Usuario {
            /** @var GoogleUser $cuenta */
            $cuenta = $cliente->fetchUserFromToken($token);

            return $this->buscarUsuario(
                (string) $cuenta->getId(),
                mb_strtolower((string) $cuenta->getEmail()),
                true === ($cuenta->toArray()['email_verified'] ?? false),
            );
        }));
    }

    /** Separado para poder probar las reglas de asociación sin hablar con Google. */
    public function buscarUsuario(string $googleId, string $email, bool $correoVerificado): Usuario
    {
        $usuario = $this->usuarios->findOneBy(['googleId' => $googleId]);
        if (null !== $usuario) {
            return $usuario;
        }

        if (!$correoVerificado || '' === $email) {
            throw new CustomUserMessageAuthenticationException('acceso.google_correo_no_verificado');
        }

        $dominio = ltrim(mb_strtolower(trim($this->dominio)), '@');
        if ('' !== $dominio && !str_ends_with($email, '@'.$dominio)) {
            throw new CustomUserMessageAuthenticationException('acceso.google_dominio', ['%dominio%' => $dominio]);
        }

        $usuario = $this->usuarios->findOneBy(['email' => $email])
            ?? throw new CustomUserMessageAuthenticationException('acceso.google_sin_cuenta', ['%email%' => $email]);

        if (null !== $usuario->getGoogleId()) {
            // El correo ya está asociado a otra cuenta de Google.
            throw new CustomUserMessageAuthenticationException('acceso.google_sin_cuenta', ['%email%' => $email]);
        }

        return $usuario->setGoogleId($googleId);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $idioma = $request->getSession()->remove(self::IDIOMA_SESION) ?? 'es';
        $destino = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($destino ?? $this->urlGenerator->generate(
            // Un lector no entra al panel: va a la lista de guías (verá las restringidas que tenga asignadas).
            \in_array(Rol::Lector->value, $token->getRoleNames(), true) ? 'guias' : 'panel',
            ['_locale' => $idioma],
        ));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $sesion = $request->getSession();
        $sesion->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urlGenerator->generate('acceso', ['_locale' => $sesion->remove(self::IDIOMA_SESION) ?? 'es']));
    }
}
