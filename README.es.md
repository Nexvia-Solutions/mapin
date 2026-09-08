# Mapin

Un grafo de código consultable para aplicaciones Laravel: inyección de dependencias, bindings del
contenedor, rutas, vistas, relaciones de Eloquent, documentación en Markdown y llamadas de
JavaScript a rutas, conectados de una forma que una herramienta genérica basada en tree-sitter no
puede ver.

[English](README.md) | [Español](README.es.md)

## Por qué

Las herramientas genéricas de grafo de código solo ven lo que es visible en la sintaxis de un
único archivo. La mayor parte del cableado que realmente importa en una aplicación Laravel real
sucede a través del contenedor, a través de strings (`route('nombre')`, `view('nombre')`) y a
través de convenciones (`hasMany`, `$listen`, `Route::get`), nada de lo cual un parser basado
solo en sintaxis llega a ver. Medido sobre una aplicación real de unos 900 archivos PHP, un
extractor genérico basado en tree-sitter capturó llamadas estáticas y nada más: cero edges de
inyección de dependencias, `new`, llamadas a métodos, rutas, vistas, includes de Blade o
relaciones de Eloquent. Mapin existe para cubrir exactamente esa brecha, tanto para un agente de
IA que pregunta "qué se rompe si cambio esto" por MCP, como para un desarrollador que pregunta lo
mismo desde la CLI, y para decir honestamente cuando no lo sabe: `found: false` con sugerencias
etiquetadas como tales, nunca una coincidencia parecida disfrazada de respuesta real.

## Requisitos

- PHP 8.2 o superior
- Laravel 11.45+, 12, o 13
- `ext-pdo` y `ext-sqlite3` (incluidas con PHP en casi cualquier instalación)

El soporte de Laravel 13 está verificado contra una aplicación real, todavía no por el suite de
tests automatizado: `orchestra/testbench`, el paquete que usan los propios tests de este proyecto
para simular una aplicación Laravel, todavía no tiene ninguna versión que soporte Laravel 13 al
momento de escribir esto. El código en tiempo de ejecución no depende de Testbench en absoluto,
solo el propio suite de tests de desarrollo de este paquete lo usa. Ver
[SPEC.md sección 1.14](SPEC.md) para el detalle completo y actualizado.

## Instalación

Este paquete requiere `laravel/mcp`, que al momento de escribir esto todavía no tiene ninguna
versión estable (solo `v1.0.0-beta.1`). Si tu aplicación usa el `minimum-stability: stable` por
defecto de Composer (como casi cualquier aplicación Laravel), `composer require` va a rechazar
resolverlo hasta que permitas versiones pre-release para los paquetes que las necesiten:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require nexvia-solutions/mapin --dev
```

`prefer-stable: true` mantiene cada otra dependencia en su última versión estable; solo le permite
a `laravel/mcp` (y a cualquier otro paquete sin versión estable) resolver a un pre-release en vez
de fallar directamente. Este es un requisito real y permanente, atado al estado de la propia
versión de `laravel/mcp` - no un paso único que se pueda saltear una vez instalado. Ver
[SPEC.md sección 1.14](SPEC.md) para cómo se encontró esto.

El service provider se registra automáticamente (auto-discovery de paquetes de Laravel). Publicá
la configuración si querés cambiar los valores por defecto (paths indexados, ubicación del
storage, el driver LLM de la capa semántica, el percentil de hub de detección de comunidades):

```bash
php artisan vendor:publish --tag=mapin-config
```

## Para empezar

```bash
# Construir el grafo - completo la primera vez, incremental (por hash de contenido) después
php artisan mapin:build --full

# Preguntar algo
php artisan mapin:find BookController
php artisan mapin:route books.store
php artisan mapin:impact 'method:App\Services\Billing::charge'

# Cualquier tool de la lista completa, siempre en JSON
php artisan mapin:query hubs --arg=limit=10 --json

# Chequeo de salud antes de apuntar un cliente
php artisan mapin:doctor
```

El chequeo `unresolved_ratio` de `mapin:doctor` puede fallar justo después de instalar en una
aplicación que todavía tiene poco código propio (un esqueleto recién creado, o un módulo nuevo):
la mayoría de los llamados que encuentra apuntan al framework en vez de a tus propias clases, así
que hay poco contra qué resolver. Se vuelve más significativo a medida que la aplicación crece; en
una aplicación real y madura es una señal genuina que vale la pena investigar.

Todos los comandos soportan `--json`. Toda respuesta lleva un bloque `graph` que reporta qué tan
desactualizada podría estar (el commit contra el que se construyó el grafo, y cuántos archivos
cambiaron desde entonces) - nunca una suposición silenciosa sobre qué tan vigente es la respuesta.

Mantener el grafo al día es opcional, no automático - corré esto una vez por checkout para
reconstruirlo en segundo plano después de cada commit y merge:

```bash
php artisan mapin:install-hooks
# ¿corre detrás de Docker en vez de PHP directo en el host?
php artisan mapin:install-hooks --command="docker exec <container> php artisan mapin:build"
```

## Usalo desde Claude Code, Cursor, o cualquier cliente MCP

```bash
php artisan mapin:mcp
```

levanta un servidor MCP por stdio que expone cada consulta como una tool, corriendo exactamente el
mismo código que usa la CLI - una respuesta por MCP y una respuesta de `mapin:query` a la misma
pregunta nunca difieren. Ver [docs/MCP.md](docs/MCP.md) para la configuración del cliente
(`.mcp.json` de Claude Code, `.cursor/mcp.json` de Cursor, incluida la variante con `docker exec`
para una aplicación en containers).

## Qué construye

- **PHP**: clases, métodos, funciones, llamadas, `new`, inyección por constructor y por método,
  bindings del contenedor, relaciones de Eloquent, mapeo de tabla (`$table` y convención),
  migraciones, jobs y eventos despachados, listeners, observers, comandos programados.
- **Rutas y vistas**: cada ruta registrada hacia su handler y middleware, includes de Blade,
  componentes, y llamadas a `route()`/`view()` tanto desde PHP como desde Blade.
- **Markdown**: un nodo `doc`/`section` por archivo y encabezado, links entre docs, y menciones a
  una clase, ruta, tabla, vista o archivo conocidos - los nombres cortos ambiguos quedan en
  `unresolved`, nunca una adivinanza.
- **JavaScript**: llamadas `fetch`/`axios`/`$.ajax` de jQuery con una URL literal, matcheadas
  contra la ruta que solicitan.
- **Análisis**: detección de comunidades con Louvain y grado de hub/bridge sobre el grafo de
  llamadas e inyecciones, corrido a demanda (`mapin:communities`), nunca durante un build normal.
- **Documentación semántica** (opcional, `mapin:docs --with-llm`): conceptos y relaciones entre
  ellos, extraídos solo del texto de las secciones Markdown - nunca del código PHP, y nunca sin el
  flag. Local por defecto (`NullClient`); viene un driver de proveedor (Ollama).
- **Consultas sin respuesta** (`mapin:misses`): cada consulta que devolvió `found: false` queda
  registrada localmente, exportable a un archivo JSONL dentro de tu propio proyecto para que un
  equipo revise juntos los gaps reales. Puramente local - nunca una llamada de red, para ninguna
  instalación.

Cada tool de consulta está listada en [docs/MCP.md](docs/MCP.md); el contrato de comportamiento
completo, cada tipo de nodo y edge, y el razonamiento detrás de cada decisión de diseño viven en
[SPEC.md](SPEC.md) (en inglés).

## Límites que este paquete se autoimpone

- Nunca manda código a un LLM sin el flag explícito `--with-llm`, y ni siquiera ahí manda otra cosa
  que texto de secciones Markdown - nunca código PHP.
- Nunca escribe fuera de su propio directorio de storage configurado (`storage/mapin/` por
  defecto). Ningún archivo en la raíz de tu proyecto, ninguna edición a tu `.gitignore`.
- Nunca ejecuta la lógica de negocio de tu aplicación: bootear el kernel para leer el router y el
  contenedor es hasta donde llega.
- Nunca responde con una coincidencia aproximada sin decirlo.

La lista completa, con el razonamiento detrás de cada punto, está en
[SPEC.md sección 13](SPEC.md).

## Documentación

- [docs/MCP.md](docs/MCP.md) - configuración del servidor MCP y la lista completa de tools
- [docs/EXTENDING.md](docs/EXTENDING.md) - cómo agregar tu propio extractor o tool de consulta
- [SPEC.md](SPEC.md) - la especificación completa (en inglés): modelo del grafo, cada extractor y
  regla de resolución, el contrato de las queries, y un relato fase por fase de qué se construyó,
  qué se verificó contra una aplicación real, y cada bug real encontrado en el camino
- [CONTRIBUTING.md](CONTRIBUTING.md) - setup de desarrollo, cómo correr el suite de tests, estilo
  de código

## Licencia

MIT. Ver [LICENSE](LICENSE).
