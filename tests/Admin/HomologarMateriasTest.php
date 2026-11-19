<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Clasificacion\SincronizadorAsjc;
use App\Entity\Bloque;
use App\Entity\Guia;
use App\Entity\Materia;
use App\Entity\Pestana;
use App\Entity\Recurso;
use App\Entity\Seccion;
use App\Enum\Rol;
use App\Enum\TipoAcceso;
use App\Enum\TipoBloque;
use App\Enum\TipoRecurso;
use App\Tests\CreaDatosTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Homologar una materia propia a una categoría ASJC traspasa lo que la tenía y elimina la propia. */
final class HomologarMateriasTest extends WebTestCase
{
    use CreaDatosTrait;

    public function testHomologaTraspasandoRecursosGuiasEspecialistasYBloques(): void
    {
        $cliente = static::createClient();
        $cliente->disableReboot();
        $cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        SincronizadorAsjc::sincronizar(self::em()->getConnection());
        self::em()->clear();

        $repo = self::em()->getRepository(Materia::class);
        $derecho = $repo->findOneBy(['codigoAsjc' => 3308]);
        $propia = self::crearMateria('Ciencias Jurídicas', 'ciencias-juridicas')->setAsjc([3308]);
        $ana = self::crearUsuario('ana@ucn.cl', Rol::Editor)->addMateria($propia);
        // Un recurso que ya tenía la categoría no la duplica.
        $codigo = (new Recurso())->setTitulo('Código Civil')->setUrl('https://ejemplo.cl/cc')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Abierto)->addMateria($propia)->addMateria($derecho);
        $vlex = (new Recurso())->setTitulo('vLex')->setUrl('https://ejemplo.cl/vlex')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Abierto)->addMateria($propia);
        $busqueda = (new Bloque(TipoBloque::Busqueda))->setContenido(['materias' => [(string) $propia->getId()]]);
        $guia = (new Guia())->setTitulo('Derecho')->setSlug('derecho-guia')->setResponsable($ana)->addMateria($propia);
        $guia->agregarPestana((new Pestana('Inicio'))->agregarSeccion((new Seccion('Buscar'))->agregarBloque($busqueda)));
        foreach ([$codigo, $vlex, $guia] as $e) {
            self::em()->persist($e);
        }
        self::em()->flush();
        $ids = ['propia' => $propia->getId(), 'codigo' => $codigo->getId(), 'vlex' => $vlex->getId(), 'guia' => $guia->getId(), 'ana' => $ana->getId(), 'bloque' => $busqueda->getId()];

        $cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $crawler = $cliente->request('GET', '/es/admin/materias');
        self::assertCount(1, $crawler->filter('a[href$="/materias/'.$ids['propia'].'/homologar"]'));

        $crawler = $cliente->request('GET', '/es/admin/materias/'.$ids['propia'].'/homologar');
        self::assertResponseIsSuccessful();
        self::assertSame((string) $derecho->getId(), $crawler->filter('#categoria option[selected]')->attr('value')); // propuesta: su clasificación
        self::assertSame(['2 recursos', '1 guía', '1 especialista', 'Ninguna sugerencia', '1 bloque de búsqueda'], $crawler->filter('.homologar__cuentas li')->each(fn ($li) => $li->text()));

        // Sin categoría válida (la propia no está entre las opciones) no se aplica.
        $formulario = $crawler->selectButton('Homologar')->form();
        $cliente->request('POST', '/es/admin/materias/'.$ids['propia'].'/homologar', ['categoria' => (string) $ids['propia'], '_token' => $formulario['_token']->getValue()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.aviso--error', 'Elige la categoría ASJC');

        $cliente->submit($formulario, ['categoria' => (string) $derecho->getId()]);
        self::assertResponseRedirects('/es/admin/materias');
        $cliente->followRedirect();
        self::assertSelectorTextContains('.aviso--exito', 'recursos 2, guías 1, especialistas 1');

        self::em()->clear();
        self::assertNull($repo->find($ids['propia']));
        $slugs = fn ($e) => $e->getMaterias()->map(fn (Materia $m) => $m->getSlug())->getValues();
        self::assertSame(['derecho'], $slugs(self::em()->find(Recurso::class, $ids['codigo'])));
        self::assertSame(['derecho'], $slugs(self::em()->find(Recurso::class, $ids['vlex'])));
        self::assertSame(['derecho'], $slugs(self::em()->find(Guia::class, $ids['guia'])));
        self::assertSame(['derecho'], $slugs(self::em()->find(\App\Entity\Usuario::class, $ids['ana'])));
        self::assertSame([$derecho->getId()], self::em()->find(Bloque::class, $ids['bloque'])->dato('materias'));
    }

    public function testLasCategoriasAsjcNoSeHomologan(): void
    {
        $cliente = static::createClient();
        SincronizadorAsjc::sincronizar(self::em()->getConnection());
        $cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $derecho = self::em()->getRepository(Materia::class)->findOneBy(['codigoAsjc' => 3308]);
        $cliente->request('GET', '/es/admin/materias/'.$derecho->getId().'/homologar');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAlEliminarUnaMateriaPropiaSeReasignanSusRecursosAOtraMateria(): void
    {
        $cliente = static::createClient();
        $cliente->disableReboot();
        $cliente->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $repo = self::em()->getRepository(Materia::class);
        $vieja = self::crearMateria('Derecho antiguo', 'derecho-antiguo')->setAsjc([3308]);
        $nueva = self::crearMateria('Ciencias Jurídicas', 'ciencias-juridicas')->setAsjc([3308]);
        $sobra = self::crearMateria('Taller', 'taller')->setAsjc([3304]);
        $vlex = (new Recurso())->setTitulo('vLex')->setUrl('https://ejemplo.cl/vlex')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Abierto)->addMateria($vieja);
        $otro = (new Recurso())->setTitulo('Otro')->setUrl('https://ejemplo.cl/otro')->setTipo(TipoRecurso::BaseDatos)->setAcceso(TipoAcceso::Abierto)->addMateria($sobra);
        self::em()->persist($vlex);
        self::em()->persist($otro);
        self::em()->flush();
        $ids = [$vieja->getId(), $nueva->getId(), $sobra->getId(), $vlex->getId(), $otro->getId()];

        $cliente->loginUser(self::crearUsuario('admin@ucn.cl', Rol::Administrador));
        $crawler = $cliente->request('GET', '/es/admin/materias/'.$ids[0].'/eliminar');
        self::assertResponseIsSuccessful();
        // Se puede reasignar a una materia propia (y no a sí misma).
        self::assertCount(1, $crawler->filter('#destino option[value="'.$ids[1].'"]'));
        self::assertCount(0, $crawler->filter('#destino option[value="'.$ids[0].'"]'));
        $cliente->submit($crawler->selectButton('Eliminar')->form(), ['destino' => (string) $ids[1]]);
        self::assertResponseRedirects('/es/admin/materias');

        // Sin reasignar: solo se quita.
        $crawler = $cliente->request('GET', '/es/admin/materias/'.$ids[2].'/eliminar');
        $cliente->submit($crawler->selectButton('Eliminar')->form(), ['destino' => '']);
        self::assertResponseRedirects('/es/admin/materias');

        self::em()->clear();
        self::assertNull($repo->find($ids[0]));
        self::assertNull($repo->find($ids[2]));
        self::assertSame(['ciencias-juridicas'], self::em()->find(Recurso::class, $ids[3])->getMaterias()->map(fn (Materia $m) => $m->getSlug())->getValues());
        self::assertCount(0, self::em()->find(Recurso::class, $ids[4])->getMaterias());
    }
}
