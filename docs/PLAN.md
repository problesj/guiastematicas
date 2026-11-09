# Plan de implementación

Versión completa y comentada: [Plan de implementación: plataforma de guías temáticas](https://claude.ai/artifact/JHUingUeLCbDmpnLTu7NEn).
Este archivo resume las decisiones tomadas el 8 de noviembre de 2026.

## Decisiones

| Tema | Decisión |
| --- | --- |
| Base | Desarrollo propio con estilo propio, inspirado en las funcionalidades de SubjectsPlus 5 (sin reutilizar su código) |
| Institución | Bibliotecas UCN; al inicio, al menos 3 editores y 5 guías |
| Stack | Ubuntu 26.04 LTS, Apache 2.4, PHP 8.5 (PHP-FPM), MySQL 8.4, Symfony 7.4 LTS, Doctrine, Twig, Stimulus y Turbo |
| Estilo | Identidad de bibliotecas.ucn.cl y manual de normas gráficas UCN: Pantone 471 C `#AC6D33`, azul 652 C `#7E9BC0` solo como fondo, fuente Source Sans 3 |
| Inicio de sesión | Usuario y contraseña propios, LDAP de la UCN y Google (OAuth 2.0) |
| Plataforma de cursos | Moodle por LTI 1.3 |
| Catálogo | Alma: API de Alma para listas por ISBN y Primo VE para búsqueda y enlaces |
| Lista A-Z | Se importa desde el Excel actual; URL con prefijo del proxy AccesUM configurable |
| Idiomas | Interfaz en español e inglés |
| Fuera de la primera etapa | Mapa de emergencias, FAQ y Talkback; no hay guías que migrar |
| Entornos | Pruebas en una máquina virtual local; producción en un servidor UCN con certbot, a cargo de un administrador de sistemas |
| Desarrollo | El código se genera con Claude Code y se guarda en este repositorio |

## Fases

| Fase | Contenido | Duración |
| --- | --- | --- |
| 0 | Arranque y diseño: maquetas, modelo de datos | 2 semanas |
| 1 | Base técnica: Symfony, entorno Docker, CI, scripts de servidor | 2 semanas |
| 2 | Usuarios y personal: tres métodos de inicio de sesión, roles, departamentos, materias | 3 semanas |
| 3 | Recursos y lista A-Z: importación del Excel, proxy AccesUM, filtros | 3 semanas |
| 4 | Editor de guías: pestañas, secciones, bloques, arrastrar y soltar, borrador y publicado | 5 semanas |
| 5 | Listas de libros (Alma, Primo VE) y API de solo lectura | 2 semanas |
| 6 | Estilo final, panel de logo y colores, accesibilidad WCAG 2.1 AA | 2 semanas |
| 7 | LTI 1.3 con Moodle | 1 a 2 semanas |
| 8 | Manual de operación, 5 guías iniciales, capacitación y paso a producción | 2 semanas |

## Insumos pendientes

- [ ] Archivos originales del logo de Bibliotecas UCN
- [ ] Datos del LDAP de la UCN y proyecto OAuth en Google Cloud
- [ ] Registro de la herramienta LTI 1.3 por el administrador de Moodle
- [ ] URL de Primo VE (vista e institución) y clave de la API de Alma
- [ ] Copia del Excel de bases de datos y formato del prefijo del proxy AccesUM
- [ ] Dominio público para producción
- [ ] Confirmar si alguna guía necesita versión en español e inglés
