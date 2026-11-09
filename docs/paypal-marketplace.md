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
A. Busca o pregunta a "Compra inteligente con Jev"
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
| intención o "dice qué producto" < 0.7 | `needs_clarification` (no busca) |
| algún candidato es lo pedido (≥ 0.7), cubre la necesidad (≥ 2/3) y entra en el presupuesto | `recommendation` |
| hay candidatos que son lo pedido, pero ninguno entra en el presupuesto | `over_budget` |
| solo hay candidatos dudosos (0.3–0.7) dentro del presupuesto | `confirm_choice` |
| nada coincide | `no_match` |

- **Presupuesto.** Viene del campo "Presupuesto máximo" o de una cifra que Jev identifica con claridad como tope (≥ 0.7). Una cifra dudosa no se aplica y se le dice al estudiante. Una cifra que sea "dinero a reservar" no se trata como presupuesto. El presupuesto no se guarda como saldo.
- **Bitácora.** Cada decisión queda en `assistant_decisions` con modelo, probabilidades y candidatos, y se puede auditar en `/us/su/lau/compra-inteligente`.
- **Fallos.** Un fallo de Jev se trata como error (HTTP 503) y nunca se sustituye por respuestas estáticas.

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
```

Se siguen aceptando los nombres anteriores `PAYPAL_SANDBOX_*`. En el frontend solo se usa el
Client ID público: `VITE_PAYPAL_CLIENT_ID`.

## Puesta en marcha

```bash
php artisan migrate
php artisan db:seed --class=MarketplaceShowcaseSeeder         # opcional: vitrina con datos ficticios
php artisan paypal:check                                      # credenciales y permisos de la app
php artisan paypal:link-store papeleria-campus-central <tracking_id>   # vincular un comercio ya conectado
```

Comandos de soporte: `paypal:onboard-link {tracking_id}` y `paypal:seller-status {tracking_id}`. Se
mantienen como alias los nombres anteriores `paypal:sandbox-*`.

## Pruebas

```bash
php artisan test --filter='PayPal|SmartShopping|JevClient|MerchantVerification'
RUN_TYPESAFE_LIVE=1 TYPESAFE_API_KEY=... php artisan test --filter=test_live_typesafe   # prueba real opcional
```

Todas las llamadas a PayPal y a TypeSafe se simulan con `Http::fake()` y `Http::preventStrayRequests()`.
