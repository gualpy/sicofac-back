# Mapeo Legacy Java -> Laravel + Livewire

## 1) Fuentes relevadas
- `ComprobantesElectronicosOffline/base/*.script`
- `ComprobantesElectronicosOffline/base/*.log`
- `ComprobantesElectronicosOffline/base/*.properties`
- `ComprobantesElectronicosOffline/resources/*.sql`
- `ComprobantesElectronicosOffline/comprobantes/*`

## 2) Hallazgos clave del sistema legacy
- Motor actual: HSQLDB 1.8 (tablas `MEMORY`).
- Persistencia separada por archivos (no una sola base):
  - `comprobantes.script`, `emisor.script`, `producto.script`, `respuesta.script`, etc.
- Flujo de archivos de comprobantes:
  - `generados` -> `firmados` -> `enviados` -> `autorizados` o `no_autorizados`.
- Directorios y endpoints estan parametrizados:
  - `CONFIGURACION_DIRECTORIO`, `PROXY`.
- Tablas `CLIENTES` y `TRANSPORTISTAS` aparecen en `*.log` (no en `*.script`), por lo que deben tratarse como estructura existente, pero con datos no confirmados en este snapshot.

## 3) Mapeo de datos: Legacy -> MySQL/Laravel

| Legacy | Propuesta MySQL | Modelo Laravel | Uso |
|---|---|---|---|
| `EMISOR` | `emisores` | `Emisor` | Configuracion fiscal del emisor y firma. |
| `COMPROBANTES` | `secuenciales` | `Secuencial` | Secuencial por tipo (`01`,`04`,`05`,`06`,`07`). |
| `CLAVES` | `claves_acceso` | `ClaveAcceso` | Control de claves y estado de uso. |
| `RESPUESTA` | `sri_respuestas` | `SriRespuesta` | Historial de envio/autorizacion por clave. |
| `PROXY` | `proxies` | `ProxyConfig` | Proxy y URLs SRI por ambiente. |
| `BASE_DATOS` | `config_db_legacy` | `ConfigDbLegacy` | Solo para migracion/auditoria, no negocio core. |
| `CONFIGURACION_DIRECTORIO` | `config_directorios` | `ConfigDirectorio` | Paths de generados/firmados/enviados/autorizados. |
| `PRODUCTO` | `productos` | `Producto` | Catalogo de productos/servicios. |
| `IMPUESTO` | `impuestos` | `Impuesto` | Catalogo de impuestos base (IVA, ICE, etc). |
| `IMPUESTO_VALOR` | `impuesto_valores` | `ImpuestoValor` | Tarifas/codigos SRI vigentes. |
| `PRODUCTO_IMPUESTO` | `producto_impuestos` | `ProductoImpuesto` | Pivot producto <-> impuesto valor. |
| `INFO_ADICIONAL` | `producto_info_adicional` | `ProductoInfoAdicional` | Atributos extra por producto. |
| `TIPO_IVA` | `tipos_iva` | `TipoIva` | Tabla de apoyo para codigos IVA. |
| `FORMAS_PAGO` | `formas_pago` | `FormaPago` | Catalogo SRI de formas de pago. |
| `COMPENSACIONES` | `compensaciones` | `Compensacion` | Reglas de compensacion IVA. |
| `CLIENTES` (en log) | `clientes` | `Cliente` | Receptor/cliente de comprobantes. |
| `TRANSPORTISTAS` (en log) | `transportistas` | `Transportista` | Guia de remision. |

## 4) Mapeo de proceso: Java desktop -> Laravel web

| Proceso legacy | Propuesta Laravel |
|---|---|
| Generar XML en escritorio | Servicio `ComprobanteXmlService` + Job `GenerateXmlJob` |
| Firmar XML con certificado | Servicio `XadesSignerService` + Job `SignXmlJob` |
| Enviar a recepcion SRI | Servicio `SriSoapService` + Job `SendToSriJob` |
| Consultar autorizacion | Job `CheckAuthorizationJob` con reintentos |
| Guardar respuesta en tabla `RESPUESTA` | Persistir en `sri_respuestas` + `comprobantes` |
| Mover entre carpetas | Storage local/S3 con estados en DB (sin depender de mover archivos fisicos) |

Estados propuestos de comprobante en DB:
- `draft`
- `xml_generated`
- `signed`
- `sent`
- `authorized`
- `not_authorized`
- `error`

## 5) Modulos funcionales en Laravel
- `Catalogos`: productos, impuestos, formas de pago, clientes, transportistas.
- `Facturacion`: factura, nota de credito, nota de debito, guia, retencion.
- `SRI Integracion`: firma, envio, autorizacion, parsing de errores.
- `Secuenciales y claves`: control transaccional por tipo de comprobante.
- `Configuracion`: emisor, ambiente (pruebas/produccion), proxy, rutas, certificado.
- `Auditoria`: eventos de estado, trazas de jobs, reintentos.

## 6) Diseño tecnico recomendado
- Backend: Laravel 12.
- Front: Livewire 3 + Blade.
- DB: MySQL 8.
- Cola: Redis + Horizon.
- Archivos: `storage/app/comprobantes/{tenant}/{estado}`.
- Scheduler:
  - cola de autorizacion pendiente cada 1 min.
  - limpieza y archivado de XML/PDF segun politica.

## 7) Roadmap de migracion (orden recomendado)
1. Crear esquema MySQL base (migraciones) y seeds de catalogos (`impuestos`, `impuesto_valores`, `formas_pago`).
2. Migrar configuracion de emisor/proxy/directorios desde `base/*.script`.
3. Construir modulo de secuenciales + clave de acceso (con bloqueo transaccional).
4. Implementar pipeline asyncrono de XML -> firma -> envio -> autorizacion.
5. Construir UI Livewire para emision y seguimiento de estados.
6. Importar historico de catalogos/productos/clientes.
7. Pruebas E2E con ambiente SRI pruebas y luego corte a produccion.

## 8) Riesgos a controlar desde el inicio
- Concurrencia en secuenciales (doble emision).
- Diferencias de codigos SRI por fecha vigencia (`FECHA_INICIO`, `FECHA_FIN`).
- Reintentos y timeout de WS SRI.
- Dependencia en rutas absolutas legacy (deben pasar a config por ambiente).
- Tablas vistas solo en `.log` (`CLIENTES`, `TRANSPORTISTAS`) necesitan validacion final en fuente real o en uso funcional.

## 9) Entregable siguiente sugerido
- Crear las primeras migraciones Laravel para:
  - `emisores`
  - `secuenciales`
  - `claves_acceso`
  - `comprobantes`
  - `sri_respuestas`
  - `impuestos`, `impuesto_valores`, `formas_pago`
