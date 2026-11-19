# Documento técnico · Guías temáticas de Bibliotecas UCN

Documentación para desarrolladores y encargados de sistemas: cómo está construida la plataforma, su base de datos y
cómo instalarla en otro servidor. El manual para el personal de la biblioteca está en [docs/manual](../manual/README.md).

| Capítulo | Contenido |
| ------------ | ---------------------------- |
| [1. Arquitectura](01-arquitectura.md) | Tecnologías, componentes, estructura del código, seguridad e integraciones |
| [2. Modelo de datos](02-modelo-de-datos.md) | Diagramas entidad-relación, reglas del modelo y contenido de los bloques |
| [3. Instalación en otro servidor](03-instalacion.md) | Requisitos, instalación en un paso o paso a paso, configuración, traspaso de datos, actualizaciones y respaldos |
| [4. Diccionario de datos](04-diccionario-de-datos.md) | Todas las tablas y columnas (generado desde las entidades) |
| [5. Desarrollo](05-desarrollo.md) | Entorno local, pruebas, integración continua y cómo hacer cambios |

**Formatos.** Markdown (esta carpeta, se ve con diagramas en GitHub), HTML estilo wiki (`html/index.html`) y Word
(`Documento-tecnico-Guias-tematicas-UCN.docx`). Se regeneran con:

```
python3 scripts/generar-diagrama-er.py     # diagramas ER (SVG y PNG) y capítulo 4
python3 scripts/generar-manual.py tecnico  # HTML y Word
```

Versión del documento: noviembre de 2026 (rama `main`, 22 tablas, 18 migraciones).
