# LaU Marketplace: comercios verificados, PayPal y compra inteligente con Jev

LaU es la red social de estudiantes universitarios de El Salvador. Este módulo permite que
comercios **verificados por LaU** vendan en el Marketplace y cobren con **PayPal**. También
permite que los estudiantes encuentren lo que necesitan con **Jev** (TypeSafe AI), un modelo de
decisiones tipadas que evalúa productos reales.

## Flujo completo

```text
Comercio                        LaU (panel /us/su/lau)            PayPal
────────                        ──────────────────────            ──────
1. Crea su tienda en la app
2. Envía datos legales ───────► 3. Revisa NIT/NRC/documento
   (NIT, NRC, documento)           Aprueba · pide cambios · suspende
4. "Conectar con PayPal" ─────────────────────────────────────► Partner Referrals
                                                                 (action_url de un solo uso)
5. Vuelve a la app ───────────► 6. Consulta estado del vendedor ◄─ merchant-integrations
                                   payments_receivable, correo,
                                   permisos OAuth → "Cobrando"

Estudiante
──────────
A. Escribe en el buscador del Marketplace → sección "Búsqueda inteligente" arriba de los resultados normales
B. Elige un producto → resumen (comercio, producto, precio, total) calculado por el backend
C. Confirma explícitamente → botón oficial de PayPal (JS SDK, merchant-id del comercio)
D. Aprueba en PayPal → el backend verifica la orden y captura (Orders v2, intent CAPTURE)
E. Solo con la captura COMPLETED se crea el pedido pagado y se descuenta stock
```

## PayPal

| Pieza | Dónde |
|---|---|
| Cliente HTTP (OAuth, Partner Referrals, estado del vendedor, Orders v2) | `app/Services/PayPal/PayPalClient.php` |
| Evaluación del vendedor (fuente de verdad: PayPal) | `app/Services/PayPal/SellerStatus.php` |
| Verificación y conexión del comercio | `app/Services/Marketplace/MerchantVerificationService.php` |
| Checkout por producto | `app/Services/Marketplace/PayPalCheckoutService.php` |
| API del comercio | `POST/GET /api/marketplace/my/verification`, `POST /api/marketplace/my/paypal/{connect,refresh}` |
| API de pago | `POST /api/marketplace/paypal/quote`, `POST /api/marketplace/paypal/orders`, `POST /api/marketplace/paypal/orders/{id}/capture` |
| Directorio público | `GET /api/marketplace/verified-stores` |
| Panel | `/us/su/lau/comercios`, `/us/su/lau/comercios/{id}` |

Reglas de seguridad aplicadas:

- **Precio y total.** Los define el backend en centavos enteros. El navegador solo envía `product_id`, `quantity` e `checkout_attempt_id`.
- **Comercio receptor.** Se confirma con PayPal antes de cotizar, crear y capturar. Además se compara con el `payee` de la orden.
- **Validación de la orden.** Antes y después de capturar se valida la orden contra PayPal: comprador (`custom_id`), producto, comercio, monto y moneda.
- **Idempotencia.** La creación usa `lau-mkt-create-u{usuario}-{uuid}` y la captura `lau-mkt-capture-{orderId}`.
- **Captura duplicada.** Se bloquea de tres formas: un candado por orden, la consulta previa a PayPal (`COMPLETED` se rechaza) y un índice único en `orders.paypal_order_id`.
- **Captura pendiente.** Una captura `PENDING` no se informa como pagada (HTTP 202, `success:false`).
- **Errores de PayPal.** Llegan sanitizados: estado, nombre, códigos de *issue* y `debug_id`. Nunca incluyen tokens, credenciales ni cuerpos completos.
- **Documento legal.** Se guarda fuera de `public/` y solo lo descarga el panel.

## Jev (TypeSafe AI)

Jev **no conversa ni genera texto**: responde preguntas tipadas (`noul`, `choice`, `score`) con
probabilidades. Siguiendo la guía de TypeSafe, *Jev aporta juicios y el código decide*.

| Responsable | Qué hace |
|---|---|
| Laravel | Valida la entrada, extrae cifras del texto, consulta solo productos activos de comercios verificados con PayPal, compara precio con presupuesto, calcula totales y aplica la tabla de decisión |
| Jev | ¿Quiere comprar? (noul) · ¿Dice qué producto? (noul) · categoría (choice + noul de existencia) · qué significa cada cifra (choice: tope / dinero a reservar / otra) · por candidato: ¿es lo pedido? (noul), ¿cuánto cubre la necesidad? (score 0–3), relevancia académica (score 0–2) · mejor candidato (choice) |
| Estudiante | Elige, confirma el resumen y aprueba en PayPal |

Todas las preguntas viven en `app/Services/Marketplace/SmartShoppingQuestions.php` y la lógica en
`SmartShoppingService.php`. Los textos visibles son plantillas (`SmartShoppingCopy.php`) que se
completan con los resultados.

Tabla de decisión (umbrales Noul: ≥ 0.7 sí, ≤ 0.3 no, en medio "no está claro"):

| Condición | Resultado |
|---|---|
| intención < 0.3 | `not_a_purchase` |
| no dice qué producto (< 0.3) ni para qué lo necesita (< 0.7) | `needs_clarification` (no busca) |
| dice para qué lo necesita, pero la búsqueda por palabras no encuentra nada | evalúa los últimos productos de comercios con PayPal |
| algún candidato es lo pedido (≥ 0.7), cubre la necesidad (≥ 2/3) y entra en el presupuesto | `recommendation` |
| hay candidatos que son lo pedido, pero ninguno entra en el presupuesto | `over_budget` |
| solo hay candidatos dudosos (0.3–0.7) dentro del presupuesto | `confirm_choice` |
| nada coincide | `no_match` |

- **Presupuesto.** Viene del campo opcional `budget` de la API o de una cifra en la búsqueda que Jev identifica con claridad como tope (≥ 0.7). Una cifra dudosa no se aplica y se le dice al estudiante. Una cifra que sea "dinero a reservar" no se trata como presupuesto. El presupuesto no se guarda como saldo.
- **Bitácora.** Cada decisión queda en `assistant_decisions` con modelo, probabilidades y candidatos, y se puede auditar en `/us/su/lau/compra-inteligente`.
- **Fallos.** Un fallo de Jev se trata como error (HTTP 503) y nunca se sustituye por respuestas estáticas.

- **Razonamiento.** Para cada candidato, Jev elige (choice `evidence_*`) la frase de la descripción real que mejor muestra que sirve. Se cita como "Por qué te lo recomiendo" junto con razones calculadas en código: posición, presupuesto y stock bajo. Jev selecciona; no redacta.
- **Reseñas y videos.** `GET /api/marketplace/products/{id}/videos` (`ProductVideosService`) busca en YouTube Data API v3 y le pregunta a Jev, video por video, si trata de ese tipo de producto (noul ≥ 0.7). Si no queda ninguno, responde "No encontramos videos ni reseñas de este producto". Sin `YOUTUBE_API_KEY` solo devuelve un enlace de búsqueda en YouTube. El resultado se cachea 12 h.

Cliente: `app/Services/TypeSafe/JevClient.php`. Hace `POST https://api.typesafe.ai/v1/systemone` con un timeout de 20 s. Reintenta solo los códigos 429 y 529 (hasta 3 intentos, respetando `Retry-After`) y una vez ante un fallo de red. Además valida el tipo, el rango y las opciones de cada respuesta.

## Configuración (`.env`)

```text
PAYPAL_MODE=sandbox            # sandbox | live
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_PLATFORM_ACCOUNT_ID=    # Merchant ID de la cuenta Business dueña de la app Platform
PAYPAL_BN_CODE=                # opcional
FRONTEND_URL=https://lauapp.es # retorno del comercio tras conectar PayPal
TYPESAFE_API_KEY=
TYPESAFE_MODEL=jev-latest
YOUTUBE_API_KEY=               # opcional: reseñas en video (Google Cloud → YouTube Data API v3)
```

Se siguen aceptando los nombres anteriores `PAYPAL_SANDBOX_*`. En el frontend solo se usa el
Client ID público: `VITE_PAYPAL_CLIENT_ID`.

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=MarketplaceShowcaseSeeder         # opcional: vitrina con datos ficticios
php artisan paypal:check                                      # credenciales y permisos de la app
php artisan paypal:link-store papeleria-campus-central <tracking_id>   # vincular un comercio ya conectado
php artisan marketplace:demo-accounts                         # cuentas demo (estudiante y comercio); pide la contraseña
```

Comandos de soporte: `paypal:onboard-link {tracking_id}` y `paypal:seller-status {tracking_id}`. Se
mantienen como alias los nombres anteriores `paypal:sandbox-*`.

## Correo al aprobar un comercio

Al aprobar una solicitud desde el panel, LaU envía `App\Mail\MerchantApprovedMail`
(`resources/views/emails/comercio-aprobado.blade.php`) al correo de contacto del comercio, con copia
a la cuenta dueña de la tienda. Las respuestas van a `MAIL_REPLY_TO_ADDRESS`
(`lauapp.es@gmail.com`). Si el envío falla, la aprobación se mantiene, el fallo se registra en el log
y el panel lo avisa.

| Opción | Configuración |
|---|---|
| Gmail (inmediata) | `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME=lauapp.es@gmail.com`, `MAIL_PASSWORD=<contraseña de aplicación>` |
| Resend (producción) | Verificar el dominio `lauapp.es` en Resend. Luego `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD=<API key>`, `MAIL_FROM_ADDRESS=no-reply@lauapp.es` |

Resend no puede enviar desde una dirección `@gmail.com`, porque exige un dominio verificado.

## Pruebas

```bash
php artisan test --filter='PayPal|SmartShopping|JevClient|MerchantVerification|MarketplaceShowcase'
RUN_TYPESAFE_LIVE=1 TYPESAFE_API_KEY=... php artisan test --filter=test_live_typesafe   # prueba real opcional
```

Todas las llamadas a PayPal y a TypeSafe se simulan con `Http::fake()` y `Http::preventStrayRequests()`.
