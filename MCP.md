# Servidor MCP — Gestión de Pagos

Gestión de Pagos incluye un servidor **MCP** (Model Context Protocol) para que un agente IA
(p. ej. Hermes, Claude…) registre horas y pagos y consulte el saldo hablando en lenguaje natural:

> «Hoy Isis ha venido 3 horas» → `log_work(hours=3, worker="Isis")`
> «Le he pagado 72 €» → `log_payment(amount=72)`
> «¿Cuánto le debo a Isis?» → `get_balance(worker="Isis")`

Cada servicio (p. ej. *Limpieza*) tiene **personas** (p. ej. *Isis*), cada una con su tarifa y su
propio saldo. Si cambias de persona, se da de baja a la anterior en el admin (sus datos se
conservan) y la nueva empieza desde 0.

## Resumen

| | |
|---|---|
| Endpoint | `http://<servidor>:<puerto>/api/mcp` |
| Transporte | Streamable HTTP, **sin estado**, respuestas JSON (solo `POST`) |
| Autenticación | Cabecera `X-API-Key: gp_…` (o `Authorization: Bearer gp_…`) |
| Documentación | `/mcp.html` (página) · `/api/mcp-docs` (JSON) · `/api/mcp-docs?format=markdown` (para el agente) |
| Protocolo | `2025-06-18` (también acepta `2025-03-26` y `2024-11-05`) |

La documentación de `/api/mcp-docs` se genera a partir de las herramientas reales, así que
siempre está al día. Es pública pero no contiene datos: solo nombres, descripciones y parámetros.

## 1. Crear una API key

1. Abre el **panel de administración** → sección **Agentes IA (MCP)** → **Nueva API key**.
2. Ponle el nombre del agente (p. ej. `Hermes`) y elige permisos:
   - **Lectura y escritura**: consultar + registrar, corregir y eliminar movimientos.
   - **Solo lectura**: solo consultar (el agente ni siquiera ve las herramientas de escritura).
3. **Copia la key en ese momento**: solo se muestra una vez. El panel te da también el bloque de
   configuración ya rellenado.

Seguridad de las keys:

- Solo se guarda su hash SHA-256 en `data/api_keys.json` (nunca la key en claro).
- Se pueden **revocar** al instante desde el panel; las revocadas se pueden eliminar de la lista.
- El panel muestra la fecha de creación y el último uso de cada key.
- `data/` no es accesible por HTTP y no entra ni en la imagen Docker ni en el repositorio.

## 2. Configurar el agente

```json
{
  "mcpServers": {
    "gestion-pagos": {
      "type": "http",
      "url": "http://<servidor>:<puerto>/api/mcp",
      "headers": { "X-API-Key": "gp_…" }
    }
  }
}
```

Para que el agente conozca las reglas, pásale también el Markdown de
`/api/mcp-docs?format=markdown` (el servidor ya envía las instrucciones en `initialize`).

## 3. Herramientas

| Herramienta | Permiso | Para qué |
|---|---|---|
| `list_services` | lectura | Servicios configurados y su tarifa €/h |
| `list_workers` | lectura | Personas: servicio, tarifa, activa o de baja y saldo individual |
| `get_balance` | lectura | Saldo por persona, por servicio o global (con desglose por persona) |
| `list_records` | lectura | Movimientos con el saldo tras cada uno (filtros: persona, servicio, tipo, fechas, límite) |
| `monthly_summary` | lectura | Horas, trabajado, pagado y balance por mes (filtros: persona, servicio) |
| `log_work` | escritura | Registrar horas (coste = horas × tarifa de la persona) |
| `log_payment` | escritura | Registrar un pago a una persona |
| `update_record` | escritura | Corregir un movimiento por id (solo los campos indicados; también reasignar persona) |
| `delete_record` | escritura | Eliminar un movimiento por id |

Reglas que aplica el servidor:

- **Saldo** = pagado − trabajado. Negativo = pendiente de pagar; positivo = a favor.
- **Persona** (`worker`) por nombre, sin importar mayúsculas ni acentos (`"isis"`). Si no se indica, se usa
  la única persona activa del servicio; si hay varias, el servidor pide que el agente pregunte cuál.
- A una persona **de baja** no se le pueden registrar horas nuevas, pero sí pagos (para saldar lo pendiente).
- **Servicio** por nombre, sin importar mayúsculas ni acentos (`"limpieza"`). Si solo hay uno, se puede omitir.
- **Fechas** `AAAA-MM-DD` en hora de Madrid; por defecto, hoy. No se aceptan fechas futuras.
- **Tarifa**: si no se indica, la de la persona (o la del servicio si la persona no tiene).
- **Duplicados**: si ya hay un trabajo con las mismas horas (o un pago con el mismo importe) de la
  misma persona y día, se rechaza con un aviso. Si el usuario confirma, el agente repite con
  `allow_duplicate=true`.
- Cada respuesta incluye un `message` listo para confirmar al usuario y el saldo resultante.
- Los movimientos creados por un agente guardan quién los creó (`created_by = "agente: <nombre de la key>"`)
  y en el historial de la web aparecen con un icono 🤖.

## 4. Probarlo a mano

```bash
KEY=gp_…
URL=http://<servidor>:<puerto>/api/mcp

# Herramientas disponibles
curl -s -X POST $URL -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

# Saldo
curl -s -X POST $URL -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_balance","arguments":{}}}'
```

Códigos HTTP: `200` respuesta · `202` solo notificaciones · `401` key ausente, no válida o revocada ·
`405` método distinto de `POST` (no hay SSE ni sesiones).

## 5. Implementación

| Fichero | Contenido |
|---|---|
| `api/mcp.php` | Transporte MCP (JSON-RPC 2.0): `initialize`, `ping`, `tools/list`, `tools/call` |
| `api/mcp_tools.php` | Definición e implementación de las herramientas (fuente única para servidor y docs) |
| `api/mcp_docs.php` | Documentación generada (JSON / Markdown) |
| `api/apikeys.php` | Creación, verificación y revocación de API keys |
| `mcp.html` | Página de documentación |
| `.htaccess` | Rutas `/api/mcp` y `/api/mcp-docs`, cabecera `Authorization`, bloqueo de librerías internas |

Probado con el SDK oficial de MCP para Python (versiones 1.30 y 2.3): 28/28 comprobaciones generales
y 17/17 de personas (varias activas, bajas, saldos individuales, reasignación).
