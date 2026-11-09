<?php

declare(strict_types=1);

namespace App\Tests\Unidad;

use App\Servicio\VideoIncrustado;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoIncrustadoTest extends TestCase
{
    public static function enlaces(): iterable
    {
        yield 'youtube' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtube con lista' => ['https://youtube.com/watch?list=PL1&v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'];
        yield 'otro sitio' => ['https://www.scopus.com', null];
        yield 'canal de youtube' => ['https://www.youtube.com/@BibliotecasUCN', null];
    }

    #[DataProvider('enlaces')]
    public function testDireccionDelReproductor(string $url, ?string $esperada): void
    {
        self::assertSame($esperada, (new VideoIncrustado())->direccion($url));
    }
}
