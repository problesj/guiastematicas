=== Guías temáticas UCN ===
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Muestra en el sitio de Bibliotecas UCN las guías temáticas, bloques de guías y recursos de la plataforma de Guías temáticas.

== Instalación ==
1. Descarga el paquete desde la plataforma (Administración → Integraciones → «Plugin de WordPress»), que ya trae la dirección del servidor.
2. En WordPress: Plugins → Añadir nuevo → Subir plugin, elige el .zip y actívalo.
3. Revisa Ajustes → Guías temáticas UCN: debe decir «Conectado».

== Códigos cortos ==
[guias_ucn] — lista de guías publicadas. Atributos: materia (slug), q (texto), idioma (es|en), vista (lista|cuadricula), limite (número; 0 = todas), descripcion (si|no).
[guia_ucn slug="ingenieria"] — una guía con enlaces a sus páginas. Atributo: pestanas (si|no).
[guia_ucn_bloque id="12"] — un bloque de una guía, actualizado en vivo. Atributos: idioma (es|en), titulo.
[recursos_ucn] — recursos de la lista A-Z. Atributos: materia, tipo, q, limite, descripcion (si|no).

Las guías restringidas nunca se muestran. Los datos se guardan en caché los minutos indicados en los ajustes.
