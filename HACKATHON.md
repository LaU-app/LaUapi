# LaU Marketplace: verified campus commerce with PayPal, AI search and an AI shopping agent

> Submission for **Build What's Next with PayPal and AI**.
>
> **Testing environment:** the app is hosted on our own infrastructure (`lauapp.es` and `api.lauapp.es`), but this Marketplace feature is in **pre-launch testing**. Every payment runs on **PayPal Sandbox**, so no real money moves. The UI is in **Spanish**, because our users are university students in El Salvador. A glossary of the key buttons is at the end of this file.

| | |
|---|---|
| Live app | https://lauapp.es (Marketplace: https://lauapp.es/tabs/marketplace) |
| Merchant landing page | https://lauapp.es/empresas |
| Frontend repository (Vue 3 + Ionic) | https://github.com/LaU-app/LaU |
| Backend repository (Laravel 12: PayPal, Jev, agent) | https://github.com/LaU-app/LaUapi (technical detail in `docs/paypal-marketplace.md`) |
| Test accounts | see [Test accounts](#test-accounts) (passwords are in the Devpost *testing instructions*) |

---

## The problem

LaU is a social network for university students in El Salvador. It brings a feed, profiles and chat, study tools (Pomodoro and tasks) and a student marketplace together in one app.

Students constantly need study supplies: calculators, lab coats, drafting kits, books. Small local businesses want to sell to them. Today those sales happen through informal channels, which leaves two gaps:

- students have no way to know whether a seller is trustworthy;
- businesses have no simple way to accept safe online payments from a student audience.

## What we built

1. **Verified merchants.** A business applies inside the app with its legal data: legal name, NIT (tax ID), NRC for companies, legal representative and a supporting document. The LaU team reviews the application in our admin panel and approves it, requests changes or suspends the business. Approval sends an email from LaU.
2. **PayPal onboarding for each merchant.** The approved merchant connects their PayPal Business account with **Partner Referrals**. LaU then asks PayPal for the seller status and treats PayPal as the source of truth. It checks that the account can receive payments, that the primary email is confirmed and that our platform has OAuth permissions. Only then does the store get the **"Verified by LaU · PayPal"** badge and accept online payments.
3. **AI-powered search.** The Marketplace search box understands needs, not just keywords. For example: *"calculadora científica para Cálculo II, máximo $20"* or *"algo para mis clases de matemáticas"*. A *Búsqueda inteligente* (smart search) section appears above the normal results. There, **Jev by TypeSafe AI**, a typed-decision model, evaluates **only real products from verified merchants**. Each pick shows:
   - a match score;
   - the **sentence from the product description that Jev selected as evidence**;
   - reasons computed in code (rank, budget, stock) and product facts;
   - **YouTube reviews that Jev confirmed are about that kind of product**, or a clear "no videos or reviews" message.

   The student always confirms explicitly before paying with PayPal.
4. **PayPal checkout to the right merchant.** The backend computes price and total and creates an **Orders v2** order with the merchant as `payee`. The student approves it with the **PayPal JS SDK**. The backend then verifies the order with PayPal and captures it. A paid LaU order exists only after PayPal confirms the capture as `COMPLETED`.
5. **AI shopping agent with chat history.** From the smart search, the student opens a chat with an agent. The agent is an open-weight LLM with tools, and Jev supplies typed judgments and checks each reply against real data. It searches, compares, finds reviews, checks orders and prepares a purchase that the student confirms in PayPal. See [Agentic commerce](#agentic-commerce-an-open-llm-agent-with-jev-as-its-judgment-layer).
6. **After the sale.** The order shows as **"Pagado"** for both buyer and seller. The buyer can message the seller, and the product is quoted as a rich preview above the message.
7. **Campus-only delivery, with a 3D campus map.** Every product is delivered **inside a university campus**, so no one has to meet a stranger somewhere else in the country.
   - The seller picks the campus and can drop an exact pin. The server rejects pins outside the campus delivery radius.
   - Students browse **their campus** by default. A **3D map of El Salvador's universities** shows products, verified stores and business fairs per campus, plus "Where am I?" via GPS (`@capacitor/geolocation`).
   - The LaU team creates **campus fairs**, and each fair gets an announcement shown only to students of that campus.
   - Campus locations come from Google Places, and **Jev picks which candidate is the actual campus**. If Jev is unsure, nothing is saved and a person decides.
8. **The product on the web, by its photo.** Google Cloud Vision (*Web Detection*) finds pages that show the product photo. **Jev keeps only pages about the same product.** The agent can use this too (`find_product_on_web`).
9. **Students sell too.** A student without a registered business still gets a personal seller dashboard under their own name. Verification is what unlocks a store name and PayPal payments.
10. **LaU team panel inside the app** (`/equipo`). LaU staff log in with a separate, short-lived admin session that only works on the review API. They approve, request changes, suspend or re-check PayPal for each merchant. It shows only merchant applications, never other users' data.

## What we built during the hackathon

LaU existed before the Submission Period (Oct 1, 2026). It had the social feed, profiles, chat, study tools and a basic peer-to-peer marketplace with cash-on-delivery orders. Everything in this document was built **during the Submission Period**:

- verified-merchant onboarding with LaU review and PayPal Partner Referrals;
- PayPal checkout (Orders v2 with the merchant as payee, JS SDK, server-side capture);
- AI-powered search with Jev;
- the AI shopping agent with chat history;
- YouTube reviews filtered by Jev;
- the merchant landing page and the LaU team panel;
- campus-only delivery, the 3D campus map, campus fairs with targeted announcements, and finding the product on the web by its photo.

The commit history of both repositories shows this work.

## How we use PayPal

| PayPal capability | Where | Why |
|---|---|---|
| OAuth 2.0 `client_credentials` | `app/Services/PayPal/PayPalClient.php` | Platform access token, kept in memory only and never logged |
| **Partner Referrals API v2**: `POST /v2/customer/partner-referrals` | Merchant "Connect PayPal" | Onboard each verified merchant with a `tracking_id` and a `return_url` back to LaU |
| **Merchant Integrations**: `GET /v1/customer/partners/{partner}/merchant-integrations?tracking_id=` and `/{merchant_id}` | Seller status | Source of truth: `payments_receivable`, `primary_email_confirmed`, `oauth_integrations` with our client ID and the required scopes |
| **Orders API v2**: create, show and capture (`intent: CAPTURE`) | Checkout | `payee.merchant_id` = the merchant; `PayPal-Request-Id` idempotency for create and capture; amount, currency, payee and buyer validated before and after capture |
| **PayPal JavaScript SDK** (Buttons, `merchant-id`) | Payment sheet | Buyer approval in the official PayPal popup |

Safety rules:

- **Prices and totals.** Computed server-side in integer cents. The browser only sends a product ID and a quantity.
- **Duplicate captures.** Blocked three ways: a lock, a status check with PayPal and a unique index.
- **Pending captures.** A `PENDING` capture is never shown as paid.
- **Errors.** PayPal errors are sanitized: no tokens, secrets or full responses reach the API or the logs.

## How we use AI: Jev by TypeSafe AI

**Tool:** [Jev](https://typesafe.ai/), TypeSafe AI's *System One* decision model (`jev-latest`). We call it from Laravel's HTTP client at `POST https://api.typesafe.ai/v1/systemone`. Jev doesn't generate text. It answers typed questions (`noul` = probability that a statement is true, `choice`, `score`) with calibrated probabilities. That makes it a good fit for commerce: the AI makes judgments, and our code makes the decisions.

| Who | Responsibility |
|---|---|
| **Laravel** | Validates input, extracts money amounts from the text, loads **only active products from verified merchants that accept PayPal**, compares prices with the budget, computes totals and applies an explicit **decision table** |
| **Jev** | *Does the student want to buy something?* · *Do they say what item they need?* · *Do they say what it is for (a class, a project)?* · *Which category?* · *What does each amount in the message mean (max price, money to keep aside, other)?* · per real candidate: *Is this the kind of item asked for?* (noul), *How well does it cover the need?* (score 0–3), *How useful is it for the course?* (score 0–2) · *Which candidate fits best?* (choice) · *Which sentence of the real product description best shows it fits?* (choice; quoted to the student as evidence) · for reviews: *Is this YouTube video about this kind of product?* (noul per video) |
| **Student** | Picks a product, confirms the summary (merchant, product, price, total) and approves in PayPal |

Decision table, using conservative thresholds (≥ 0.7 counts as yes, ≤ 0.3 as no, and anything in between as unclear):

- `recommendation`: a candidate is what was asked for, covers the need and fits the budget.
- `over_budget`: matching candidates exist, but none fits the budget.
- `confirm_choice`: only uncertain matches; the student confirms.
- `needs_clarification`: the request names neither an item nor what it is for, so the search asks for details first.
- `not_a_purchase` and `no_match`.

What the AI **never** does:

- invent products, prices or stock;
- see prices or compute totals (the budget comparison is done in code);
- trigger a payment.

A failed AI call is an error, never a fallback answer. Every decision is stored with its probabilities and shown in the admin panel (*Compra inteligente*) for auditability.

## Agentic commerce: an open LLM agent with Jev as its judgment layer

From the smart search, the student taps **Abrir chat** (open chat) and keeps going in a conversation with the **Asistente de compras** (shopping assistant). Chats are saved in a per-student **history**. The assistant can search, compare, check reviews, look up the student's orders and **prepare a purchase**. The student still confirms and pays in PayPal.

**Model:** [Qwen 3.5](https://ollama.com/library/qwen3.5), an open-weight LLM with native tool calling and reasoning. During development it runs locally with [Ollama](https://ollama.com) (`qwen3.5:9b`, or `qwen3.5:4b` on smaller GPUs). Our server has no GPU, so on the hosted demo the same kind of open-weight model is served through an OpenAI-compatible provider (for example `openai/gpt-oss-120b` on Groq). Switching takes three environment variables, with no code changes.

The agent loop lives in `app/Services/Agent/ShoppingAgent.php`:

1. **Gate (Jev).** *Is the student's message about finding, comparing or buying products, or about their orders?* (noul). If the answer is clearly no (≤ 0.3), code replies with a fixed message and the LLM is never called.
2. **Plan and act (LLM).** The LLM decides which tool to call: `search_products` (the smart search above, so Jev ranks real products), `get_product_details`, `get_product_reviews`, `propose_purchase` or `get_my_orders`. Code caps the loop at 5 steps and 8 tool calls.
3. **Verify (Jev).** Each sentence of the draft reply is checked against `facts`, the real data the tools returned. Jev answers two Nouls per sentence: *does it state a product or order fact?* and *is every fact it states found in `facts`?*. A sentence that makes a claim (≥ 0.7) that isn't supported (≤ 0.3) is sent back to the LLM once for correction. If it still fails, code removes it. Every probability is stored for audit.
4. **Act safely (code).** `propose_purchase` validates stock, store status and quantity, and computes the total in integer cents. The student gets a **"Confirmar y pagar"** (confirm and pay) card that opens the normal PayPal checkout. The agent never calls PayPal.

So the LLM brings planning and conversation, Jev brings calibrated judgments, and code makes every decision about money.

**Tools used during development:** Claude Code (Anthropic) as a coding assistant.

## Architecture

```mermaid
flowchart LR
  subgraph Student["Student (Vue 3 + Ionic, lauapp.es)"]
    A[Search box → smart search] --> B[Recommendations + reviews]
    B --> CH[Chat with the shopping agent]
    B --> C[Payment sheet: confirm + PayPal JS SDK]
    CH --> C
  end
  subgraph API["LaU API (Laravel 12, api.lauapp.es)"]
    AG[ShoppingAgent<br/>tool loop · gate · verify] -->|tools| D
    AG -->|chat + tool calls| L[(Open LLM · Qwen 3.5 / gpt-oss)]
    AG -->|gate + grounding checks| J
    D[SmartShoppingService<br/>decision table] -->|typed questions| J[(Jev · TypeSafe AI)]
    E[PayPalCheckoutService<br/>quote · create · capture]
    F[MerchantVerificationService<br/>KYB review · PayPal onboarding]
    G[(MySQL: stores, orders,<br/>store_verifications,<br/>assistant_decisions)]
  end
  subgraph PP["PayPal (Sandbox)"]
    P1[Partner Referrals]
    P2[Merchant Integrations]
    P3[Orders v2]
  end
  A --> D
  CH --> AG
  C --> E --> P3
  E --> P2
  F --> P1
  F --> P2
  Admin[LaU admin panel] --> F
  D & E & F --> G
```

## Test accounts

| Role | Where | Account |
|---|---|---|
| Student (buyer) | LaU, https://lauapp.es | `estudiante.demo@lauapp.es` |
| Merchant (seller, "Papelería Campus Central") | LaU, https://lauapp.es | `comercio.demo@lauapp.es` |
| PayPal buyer | PayPal Sandbox popup | `sb-g9x2153766254@personal.example.com` |

All passwords are in the Devpost **testing instructions** and are not published in this repository. PayPal Sandbox accounts hold only test money.

## How to test (about 5 minutes, two browsers)

Use **two browsers, or one normal window and one private window**, so you can be the buyer and the seller at the same time.

**Browser A: student**

1. Open https://lauapp.es → **Iniciar sesión** (log in) with the student account.
2. Go to **Marketplace** (shop icon in the bottom bar).
3. In the search box, type `calculadora científica para Cálculo II, máximo $20`. Press **Enter**, or the ✨ button inside the field.
4. The **Búsqueda inteligente** section appears above the normal results while the AI works. It recommends **"Calculadora científica 417 funciones"** and shows:
   - a match ring;
   - **"Por qué te lo recomiendo"** (why I recommend it): a sentence quoted from the product description, plus the reasons;
   - stock and the other product facts.
5. Open **Reseñas y videos** (reviews and videos). You'll see YouTube reviews the AI judged relevant, or a clear "no videos or reviews" message.
6. Tap **Abrir chat** (open chat) below the results. The **Asistente de compras** opens with the same results. Try:
   - `Compará las opciones` (compare the options);
   - `¿Tiene reseñas?` (does it have reviews?);
   - `Quiero comprarla` (I want to buy it): the agent prepares a **"Confirmar y pagar"** card.

   The chips above each reply show which tools the agent used. Your chats stay in the history (clock icon).
7. Tap **Comprar** (Buy) or **Confirmar y pagar**. Review the summary (merchant, product, price, total), tick the confirmation box, and click the **PayPal** button.
8. In the PayPal popup, log in with the **PayPal Sandbox buyer** account and pay.
9. You get a receipt with a LaU order code. **Ver mis compras** shows the order as **"Pagado"** (paid). Back in the chat, ask `¿Cómo va mi último pedido?` (how is my last order?).
10. Open the product again → tap the chat icon (**Escribir al vendedor**). The message comes pre-filled with the **product preview card** above it. Send it.

**Browser B: merchant**

1. Log in with the merchant account → **Marketplace** → **Mis ventas** (orders, *Ventas* tab). The new order appears as **Pagado**.
2. Open **Chat** to see the buyer's message with the product preview.
3. **Marketplace → ¿Tenés un comercio?** shows the verified status and the PayPal connection.

**Optional: onboard a brand-new merchant**

1. Create a new LaU account → **Marketplace → ¿Tenés un comercio?** → create a store → submit the legal form. For testing, any 14-digit NIT such as `0614-010190-101-3` and any PDF are fine.
2. The LaU team approves applications during judging. You'll receive an email and the app shows **"Conectar con PayPal"**.
3. Connect with any PayPal **Sandbox Business** account; you can create one at developer.paypal.com → Testing Tools → Sandbox Accounts. Back in LaU, tap **Ya terminé, verificar**: LaU asks PayPal for the seller status and enables payments.

## Run it locally

1. **Backend (`LaUapi`).** Follow `docs/paypal-marketplace.md`:
   1. `composer install && npm ci && npm run build`
   2. copy `.env.example` to `.env`, then set the `PAYPAL_*` and `TYPESAFE_API_KEY` variables. For the chat agent, install [Ollama](https://ollama.com), run `ollama pull qwen3.5:9b` (or `qwen3.5:4b` on GPUs under 8 GB), and set `AGENT_LLM_BASE_URL=http://127.0.0.1:11434/v1` and `AGENT_LLM_MODEL=qwen3.5:9b`. Any OpenAI-compatible endpoint also works, for example Groq with `AGENT_LLM_API_KEY`.
   3. `php artisan migrate`
   4. `php artisan db:seed --class=MarketplaceShowcaseSeeder`
   5. `php artisan paypal:link-store papeleria-campus-central <your sandbox tracking_id>`
   6. `php artisan marketplace:demo-accounts`
   7. `php artisan serve`
2. **Frontend (this repo).**
   1. `npm ci`
   2. create `.env.development.local` with `VITE_API_URL=http://127.0.0.1:8000/api` and `VITE_PAYPAL_CLIENT_ID=<your sandbox client ID>`
   3. `npm run dev`
3. **Tests.**
   - Backend: `php artisan test --filter='PayPal|SmartShopping|ShoppingAgent|ProductVideos|JevClient|MerchantVerification|AdminMerchantApi|MarketplaceShowcase'`. PayPal, TypeSafe and the LLM are mocked with `Http::fake`; an optional live Jev test is included.
   - Frontend: `npx vitest run tests/unit/paypalCheckout.spec.ts tests/unit/shoppingAgent.spec.ts`.

## Requirements checklist

| Requirement | How LaU meets it |
|---|---|
| Meaningful PayPal integration (Sandbox) | Partner Referrals onboarding, Merchant Integrations seller status, Orders v2 create/show/capture with payee, PayPal JS SDK, all on Sandbox |
| Meaningful AI | Jev (TypeSafe AI) drives the smart search: understanding, candidate evaluation, selection and evidence. An open-weight LLM agent (Qwen 3.5 / gpt-oss) plans and uses tools, with Jev gating and fact-checking every reply. All of it has an auditable log |
| Working prototype | Hosted at lauapp.es; end-to-end purchase with real PayPal Sandbox payments |
| Documentation | This file, `docs/paypal-marketplace.md` (backend) and inline code docs |
| Tools disclosed | PayPal APIs and JS SDK; Jev by TypeSafe AI (judgments); Qwen 3.5 via Ollama, or an open-weight model via Groq on the hosted demo (agent LLM); YouTube Data API v3 (reviews); Google Cloud Vision Web Detection (product photo → web pages); Google Maps JavaScript API with 3D Maps (preview) and Places API (New) (campus map and locations); `@capacitor/geolocation` (GPS); Claude Code (development assistant) |
| Existing project, significant update | See [What we built during the hackathon](#what-we-built-during-the-hackathon) |
| Public repo + license | MIT license (see `LICENSE`) |
| Demo video | Pending (under 3 minutes, YouTube) |

## UI glossary (Spanish → English)

| UI | Meaning |
|---|---|
| Búsqueda inteligente | Smart (AI) search |
| Asistente de compras · Abrir chat | Shopping assistant (agent) · Open chat |
| Confirmar y pagar | Confirm and pay |
| Por qué te lo recomiendo | Why I recommend it |
| Reseñas y videos | Reviews and videos |
| Tu panel de ventas | Your seller dashboard |
| Comprar / Comprar con PayPal | Buy / Buy with PayPal |
| Confirmo que quiero comprar… | I confirm I want to buy… |
| Pagado | Paid |
| Mis compras / Mis ventas | My purchases / My sales |
| ¿Tenés un comercio? | Do you have a business? |
| Verificado por LaU | Verified by LaU |
| Conectar con PayPal | Connect with PayPal |
| Ya terminé, verificar | I'm done, verify |
