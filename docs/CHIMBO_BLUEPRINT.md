# CHIMBO — Development Blueprint

> **Version 2.1** · 2026-09-28 · Status: **In progress — Phase 0**
> v2.1: backend uses SCMRS-style classes + helpers + API files (no MVC layers); clear names, no abbreviations; prefixed DB column names.
> Source of truth: `CHIMBO.pdf` (12 pages of screens + notes).
> **[DOC]** = found in the document. **[REC]** = recommended addition for a production-ready product.
> v2.0 changes: code locations fixed (XAMPP + AndroidStudioProjects), a **PHP + JavaScript/AJAX web storefront** added, the API serves both apps, the backend is simplified (no public/ subfolder, no namespaces, no virtual host).

---

## 0. Quick start — where everything lives

CHIMBO is **one backend and three front-ends**:

| Part | Technology | Folder | Local URL |
|---|---|---|---|
| **Backend API** (JSON for the mobile app and the website's AJAX) | PHP 8.2 OOP + MySQL | `C:\xampp\htdocs\chimbo\api\` + `src\` | `http://localhost/chimbo/api/v1/...` |
| **Web storefront** (customers, in the browser) | PHP pages + Bootstrap 5 + plain JavaScript (`fetch`/AJAX) | `C:\xampp\htdocs\chimbo\` (root pages) | `http://localhost/chimbo/` |
| **Admin dashboard** (CHIMBO staff) | PHP pages + Bootstrap 5 | `C:\xampp\htdocs\chimbo\admin\` | `http://localhost/chimbo/admin/` |
| **Mobile app** (customers, Android first, iOS later) | Flutter + Dart | `C:\Users\edgar\AndroidStudioProjects\chimbo\` | Emulator → `http://10.0.2.2/chimbo/api/v1` · real phone → `http://<PC-LAN-IP>/chimbo/api/v1` |
| **Docs** (this blueprint, API notes, decisions) | Markdown | `C:\xampp\htdocs\chimbo\docs\` (blocked from the web) | — |

```
C:\xampp\htdocs\chimbo\                  ← PHP project (git repo #1). XAMPP serves it like SCMRS.
C:\Users\edgar\AndroidStudioProjects\chimbo\   ← Flutter project (git repo #2). Open in Android Studio.
```

**Why this split:** XAMPP already serves `htdocs` (no extra setup, same as SCMRS); Android Studio already works with `AndroidStudioProjects` (same as Zala Salama). The **shared business logic lives once**, in `htdocs\chimbo\src\`, and is used by the API, the web storefront and the admin.

**Build order (default):** Phase 0 foundation → backend + admin + **mobile app** feature by feature (Phases 1–6) → **web storefront** (Phase 7) → polish & launch. The web storefront is fast to build at that point because it reuses the finished API and classes. If you prefer the website first, swap the "client" step in Phases 1–5 (see §23, D-1). Phase 0 is identical either way.

---

## Table of contents

0. Quick start — where everything lives
1. Product overview
2. Requirements extracted from the document
3. Recommended additional requirements
4. User roles
5. Main user journeys
6. UI/UX direction (app + web)
7. System architecture
8. Flutter (mobile app) architecture
9. PHP backend architecture
10. Web storefront architecture (PHP + JavaScript/AJAX)
11. MySQL database design
12. API architecture
13. Payment architecture
14. Admin architecture
15. Security considerations
16. MVP scope
17. Future features
18. Development phases
19. Implementation order (for the coding agent)
20. Testing strategy
21. Deployment strategy
22. Technical risks and mitigations
23. Decisions and open questions
24. Step-by-step roadmap we follow together

---

## 1. Product overview

**CHIMBO** is a Swahili-first **B2B wholesale marketplace** for small retail business owners in Tanzania (duka owners, beauty shops, resellers) to restock **cosmetics and jewelry** at wholesale prices, from a mobile app or a website.

| Trait | Evidence in the document |
|---|---|
| Customers are **business owners**, not end consumers | "Mmiliki wa Biashara", "Tuambie Kuhusu Biashara Yako", "Biashara Imethibitishwa" |
| **Tiered (volume) pricing** — buy more, pay less per piece | "Bei za Jumla Zinazokulipa", "Nunua zaidi, lipa kidogo" table, "Unaokoa TZS 4,000" |
| **Minimum Order Quantity (MOQ)** per product | "MOQ 1 pc", "MOQ 12 pcs", "MOQ imetimia" |
| **Repeat buying** is the core loop | "Agiza Tena", "Uliagiza Hivi Karibuni / Recently Ordered", "Reorder" |
| **Verified sellers** supply the products | "Muuzaji Aliyethibitishwa" on every product |
| **Mobile-money-first payments** | M-Pesa, Airtel Money, Mixx by Yas, Benki, Lipa ukipokea |
| **Phone-number identity** (no password) | Registration = phone → OTP → business info | 

Brand: tagline **"BIDHAA BORA • BEI NAFUU • BIASHARA IMARA"**, sub-tagline **"Agiza. Amini. Pokea. Kuza Biashara."** (replaces "Jaza stock. Kuza biashara." per p.3 — confirm, D-3), colours deep green + orange on warm cream, truck/cart logo mark. Existing landing page: `https://kachimbo.efolder.fun/`.

---

## 2. Requirements extracted from the document [DOC]

Page numbers refer to `CHIMBO.pdf`. These screens are the design reference for the **mobile app**; the web storefront follows the same content and flows in a responsive layout (§10).

### 2.1 Onboarding (p.1)
- **Splash:** logo, tagline, sub-line.
- **Slide 1 — "Bidhaa za Biashara Yako":** "Jewelry na Cosmetics — zote sehemu moja", chips JEWELRY / COSMETICS, page dots, **Endelea**.
- **Slide 2 — "Bei za Jumla Zinazokulipa":** "Nunua zaidi. Okoa zaidi. Pata faida zaidi.", example tier ladder, badge "OKOA ZAIDI", **Endelea**.
- **Slide 3 — "Agiza kwa Urahisi. Pokea Haraka.":** "Chagua • Lipa • Pokea", "Tunafikisha oda yako salama na kwa wakati.", **Anza Sasa** → registration.

### 2.2 Registration (p.1, p.12)
Progress "Hatua X kati ya 3", back arrow on steps 2–3:
1. **Karibu CHIMBO** — "Anza kwa namba yako ya simu." 🇹🇿 +255 picker + phone (7XX XXX XXX). "Tutaitumia kuthibitisha akaunti na kukufikishia oda." **Endelea**. "Kwa kuendelea, unakubali Masharti na Sera ya Faragha."
2. **Thibitisha Namba** — "Tumeweka namba ya uthibitisho kwenye +255 …", **6-digit code**, **Thibitisha**, "Hukupokea namba? **Tuma tena**", **Badilisha namba**.
3. **Tuambie Kuhusu Biashara Yako** — Jina kamili; Jina la biashara/duka (*Si lazima*); Mkoa (dropdown); Wilaya (dropdown, *Si lazima*). **Ingia CHIMBO**. "Unaweza kubadilisha taarifa hizi baadaye."
- p.12 "What's Next?" is blank → decision D-5.

### 2.3 Home (p.2, revised on p.3)
- Header: logo + tagline, **notification bell with badge**.
- Greeting card (p.3 revision): **time-based greeting** ("Good Morning, Joyce! 👋"), **avatar**, **"Agiza. Amini. Pokea. Kuza Biashara."**
- **Search bar with camera icon**.
- **Category cards:** COSMETICS — "Shamba la Vipodozi"; JEWELRY — "Mrembo Muuza Urembo"; **Angalia / Browse →**.
- **Quick actions:** Ofa (Hot Deals), Bidhaa Mpya (New Arrivals), Zinazouzwa Sana (Best Sellers), Agiza Tena (Reorder).
- **Banner:** "BEI ZA JUMLA — Nunua zaidi, okoa zaidi" + **Nunua Sasa**.
- **Uliagiza Hivi Karibuni / Recently Ordered** rail + **View all**.
- **Bottom navigation:** Nyumbani, Gundua, Kikapu (badge), Oda, Wasifu.

### 2.4 Category listing — Cosmetics (p.4)
- App bar: back, title **Cosmetics**, subtitle **Shamba la Vipodozi**, cart with badge.
- Scoped search "Tafuta kwenye Cosmetics…" + camera icon.
- **Sub-category chips:** Zote, Skin Care, Hair Care, Body Care, Baby Care, Women Care, Sanitary, Makeup.
- **Filter bar:** Chuja, Panga, Bei ▾, MOQ ▾.
- **2-column grid.** Card: image, optional "Bestseller" badge, name, price (TZS), MOQ, quick-add (+), "✓ Muuzaji Aliyethibitishwa".
- Sample data: Vaseline Petroleum Jelly 400ml (5,500 · MOQ 1), Hair Food 500ml (6,000 · 1), Nivea Body Lotion 400ml (7,000 · 1), Dove Beauty Soap 100g ×3 (4,200 · 3), Nourish Body Oil 100ml (4,800 · 2), Dettol Wet Wipes 50s (3,200 · 4).

### 2.5 Category listing — Jewelry (p.5)
- Author's note: **title "Jewelry", subtitle "Mrembo Muuza Urembo"**, same pattern as Cosmetics (the note overrides the mock).
- Search "Tafuta jewelry au accessories…".
- **Chips:** Zote, Earrings, Necklaces, Bracelets, Rings, Anklets, Watches, Hair Accessories, Bags.
- Same filter bar and cards (photo-led).
- Sample data: Necklace + Earrings set (8,500 · MOQ 6), Hoop Earrings Set (1,200 · 12), Gold Plated Bangles (9,200 · 6), Stainless Steel Rings (2,500 · 12), Anklet Set (3,800 · 6), Classic Watch (18,000 · 3).

### 2.6 Product details (p.6)
- Back, **favourite (heart)**, **share**.
- **Gallery** with **zoom** and **thumbnails**.
- Name, **seller + verified badge**, **rating "4.8 (256 maoni)"**.
- Info strip: **MOQ**, **Stock (500+ pcs)**, **Uwasilishaji siku 2–3**.
- **Tier table "Nunua zaidi, lipa kidogo":** 1–5 → 5,500; 6–23 → 5,000; 24–59 → 4,700; 60+ → 4,400.
- **Stepper "Kiasi"** + **"Unaokoa TZS 4,000"**.
- **Uliza Muuzaji** and **Ongeza Kikapuni**.

### 2.7 Cart — "Kikapu Chako" (p.7)
- **Hariri** (edit mode); banner **"Ongeza TZS 10,000 upate bei nzuri zaidi."**
- Items **grouped by category**, tag "Bei ya jumla".
- Line: image, name, applied tier ("6+ pcs @ TZS 5,000"), stepper, line total, **"MOQ imetimia"**.
- Summary: Bidhaa (n), Usafirishaji, **Punguzo**, **Jumla**; CTA **Endelea kwenye Malipo**.

### 2.8 Checkout — "Malipo na Usafirishaji" (p.7)
- **Stepper:** Anwani → Usafirishaji → Malipo → Hakiki.
- Address card + **Badilisha**.
- **Delivery:** Standard (siku 2–3) TZS 5,000; Haraka (kesho) TZS 10,000.
- **Payment:** M-Pesa, Airtel Money, Mixx by Yas, Benki, Lipa ukipokea.
- Summary; **Endelea Kuhakiki**.

### 2.9 Review & confirmation (p.8)
- **Hakiki Oda:** address, delivery, payment, item thumbnails, totals, **Thibitisha Oda**, "Malipo yako ni salama."
- **"Oda Imepokelewa!":** "Asante, Joyce. Tumeanza kuandaa oda yako.", **order number `#CHB123456`**, total, delivery estimate, **Fuatilia Oda**, **Rudi Nyumbani**.

### 2.10 Orders & tracking (p.9)
- **Oda Zangu:** filter; tabs **Zinazoendelea / Zimefika / Zote**. Active card: status, ETA, **Fuatilia Oda**. Delivered card: date, thumbnails, total, **Agiza Tena**, **Pakua Risiti**.
- **Fuatilia Oda:** banner "Njiani — Oda yako inakuja.", **timeline** Imethibitishwa → Imepakiwa → Imetumwa → Inasafirishwa → Imewasili (with dates), **map**, ETA, **delivery agent card** (photo, name, phone, call + message).

### 2.11 Profile — "Wasifu" (p.10, alternative p.11)
- Menu: **Taarifa za Biashara, Anwani za Usafirishaji, Njia za Malipo, Risiti na Historia, Arifa, Msaada, Mipangilio, Toka**.
- p.10: photo, "Biashara Imethibitishwa" badge. p.11: initials avatar, phone, location, **Hariri Wasifu**, subtitles per row, settings shortcut, outlined **Toka kwenye Akaunti**. (Choice: D-4.)

### 2.12 Mockup inconsistencies (resolve, don't copy)
| # | Issue | Handling |
|---|---|---|
| I-1 | Onboarding tiers ≠ product tiers | Onboarding numbers are illustrative |
| I-2 | Cart marks lines below MOQ as "MOQ imetimia" | MOQ enforced by the server; badge shows real state |
| I-3 | "Bidhaa (4)" in cart vs "Bidhaa (8)" in review | Count = product lines; total pieces shown separately |
| I-4 | "Punguzo −3,600" has no rule | Needs a rule (D-7) |
| I-5 | Home in English (p.3), everything else Swahili | Bilingual, Swahili default (D-2) |
| I-6 | Splash/website still say "Jaza stock" | Replace everywhere (D-3) |
| I-7 | Mixed card layouts in the cosmetics grid | One consistent card component |

---

## 3. Recommended additional requirements [REC]

| # | Requirement | Why | When |
|---|---|---|---|
| R-1 | Returning-user login = same phone + OTP (step 3 skipped if complete) | Only first-time registration is shown | MVP |
| R-2 | Stay logged in, logout | Basic expectation | MVP |
| R-3 | Delete account in-app | Google Play / App Store policy | MVP |
| R-4 | Terms & Privacy pages | Referenced but not designed; data-protection law | MVP |
| R-5 | Admin dashboard | Nothing can be sold without it | MVP (built per phase) |
| R-6 | Server-side cart shared by app + website | Same cart on phone and browser; enables reorder | MVP |
| R-7 | Stock reservation at order time | Prevents overselling | MVP |
| R-8 | "Waiting for payment" screen | Mobile money is asynchronous (PIN on phone) | MVP |
| R-9 | Cancel order before packing | Common need | MVP |
| R-10 | In-app notifications list + bell badge | Bell exists in the doc | MVP |
| R-11 | Push notifications (FCM) | Re-engagement | v1.1 |
| R-12 | Loading / empty / error / offline states on every screen | Polished UX on weak networks | MVP |
| R-13 | Kiswahili + English | Doc shows both; p.11 "Lugha" | MVP |
| R-14 | Audit log of admin and payment actions | Financial accountability | MVP |
| R-15 | Business verification (admin toggle) | "Biashara Imethibitishwa" | MVP |
| R-16 | Support call/WhatsApp (Msaada, and "Uliza Muuzaji" in v1) | Chat is a big feature | MVP |
| R-17 | Price/stock change handling at checkout | Prices can change | MVP |
| R-18 | Payment reconciliation job | Webhooks can be lost | MVP |
| R-19 | OTP rate limits / anti-SMS-fraud | SMS credit abuse | MVP |
| R-20 | **Web storefront** with the same account, cart and orders as the app | Customers on computers; Google can index products | MVP (Phase 7) |
| R-21 | Crash reporting & analytics | Field issues | v1.1 |
| R-23 | **PIN login** (4–6 digits, like M-Pesa) — phone → PIN; SMS code only for new numbers and "Umesahau PIN?" (design in §12.5) | Saves an SMS per login; works when SMS is slow | Before the first beta (built 2026-09-30) |
| R-22 | **Time-limited offers ("Ofa")** — a % discount on a product for a set period, with a countdown (design in §12.4) | Brings shop owners back; clears slow stock | Before the first beta |

---

## 4. User roles

| Role | Where | Description | MVP |
|---|---|---|---|
| **Guest** | App / web | Not logged in. **App:** onboarding, then registration (PDF flow). **Web:** browses the catalog, sees prices and fills a cart as a guest; logs in (phone + OTP) at checkout — the guest cart then moves into the account. Orders, wishlist and profile ask for login when opened. (D-6) | ✅ |
| **Customer / Business owner** | App / web | Verified phone; buys, tracks, reorders. Business may be unverified/verified | ✅ |
| **Super Admin** | Admin | Everything incl. admin users & settings | ✅ |
| **Catalog Manager** | Admin | Categories, sellers, products, images, stock, banners | ✅ |
| **Operations** | Admin | Orders, statuses, delivery agents, COD cash | ✅ |
| **Finance** | Admin | Payments, reconciliation, refunds, reports | ✅ |
| **Delivery Agent** | Record only | Name/phone/photo shown to customer. Own app = future | record ✅ |
| **Seller / Vendor** | Record only | Name + verified badge on products. Seller portal = future | record ✅ |

> **Assumption:** v1 is a **managed marketplace** — sellers exist as records, CHIMBO staff manage their catalog and fulfil every order. One checkout = one order = one payment. (D-1b)

---

## 5. Main user journeys

- **J1 Registration:** Splash → 3 slides → *Anza Sasa* → phone → OTP → business info → Home. (Web: *Ingia* → phone → OTP → business info → back to the page the user came from.)
- **J2 Returning user:** valid token/session → Home; otherwise phone → OTP → Home.
- **J3 Discover & buy:** Home → category or search → listing → product → *Ongeza Kikapuni* → cart → Address → Delivery → Payment → Review → *Thibitisha Oda* → (mobile money: PIN on phone, waiting screen) → *Oda Imepokelewa!* → track.
- **J4 Quick add:** card (+) → sheet/popover with MOQ-prefilled stepper → add → cart badge animates.
- **J5 Reorder:** Recently Ordered / delivered order → *Agiza Tena* → items added at **current** prices; unavailable items flagged.
- **J6 Track:** Orders → *Fuatilia Oda* → timeline, ETA, agent call/WhatsApp.
- **J7 Payment failure:** waiting → failed/timeout → retry / other method / COD (if allowed) → unpaid order expires, stock released.
- **J8 Profile:** personal, business, addresses, language, notifications, help, logout, delete account.
- **J9 Admin fulfilment:** new order → Packed → Dispatched (assign agent) → In transit → Delivered (COD: confirm cash) → customer notified at each step.

---

## 6. UI/UX direction (app + web)

### 6.1 Principles
1. **Built for shop owners on budget Android phones and mobile data** — fast, light images, big tap targets.
2. **Wholesale is the hero** — tiers, MOQ and "you save" visible at card → detail → cart → review.
3. **Trust at every step** — verified sellers, delivery estimates, "Malipo yako ni salama", order numbers, named agent.
4. **Reorder in one tap.**
5. **Swahili first, plain words.**
6. **Same brand, same flows on app and web** — a customer switching devices recognises everything.

### 6.2 Marketplace patterns we adopt (original CHIMBO versions, no copying)
| Pattern | CHIMBO version |
|---|---|
| Search dominates Home, sticks on scroll | Greeting card with search; pins under the header on scroll |
| Visual category entry points | Two big category cards + sub-category chips |
| Horizontal product rails | Recently Ordered, Hot Deals, New Arrivals, Best Sellers |
| Dense 2-column grid, infinite scroll | Uniform cards: 1:1 image, 2-line name, "kuanzia TZS 4,400", MOQ, verified tick, round (+) (web: 3–5 columns on wider screens) |
| Card badges | Bestseller / New / Deal — max one per card |
| "Add N more for a better price" | "Ongeza pcs 3 upate TZS 5,000 kila moja" (from tiers) |
| Sticky bottom buy bar on product page | Stepper + total + *Ongeza Kikapuni* always visible |
| Skeleton loaders | Grey shapes of the real cards while loading |
| Card → detail image transition | Hero transition (app) |
| Cart feedback | Badge bounce + haptic (app) / toast + badge bounce (web) |
| Clear multi-step checkout | 4-step stepper as in the doc |
| Order timeline | Vertical timeline, current step in orange |
| Friendly empty states | Illustration + 1 sentence + 1 CTA |
| Success celebration | Short check-mark animation + light sparkle |

### 6.3 Visual system (shared by app, web and admin accents)
| Token | Value (approximate — confirm from logo files, D-16) |
|---|---|
| `primary` deep green | ≈ `#0E3B2A` — Endelea, Thibitisha, selected chips, active nav |
| `accent` orange | ≈ `#F39200` — buying actions (Ongeza Kikapuni, Endelea kwenye Malipo, Anza Sasa), best-tier price, badges |
| `background` cream | ≈ `#FBF6EE` |
| `surface` | `#FFFFFF`, radius 12–16 px, soft shadow |
| `danger` / `success` | red (logout, errors, counts) / green (MOQ met, delivered, verified) |
| Font | One geometric sans (e.g. Poppins) — bundled in the app, self-hosted on the web |
| Money | `TZS 5,500` — thousands separators, no decimals |

Rule: **green = navigate/confirm, orange = buy.** In Flutter these are `AppColors`; on the web they are CSS variables (`--chimbo-primary`, …) in one `theme.css`.

### 6.4 States every screen/page must have
`loading` (skeleton) · `empty` · `error` (+ *Jaribu tena*) · `offline` (banner) · `partial` (e.g. some cart items out of stock).

### 6.5 Performance budget
- App Home usable < 2 s on mid-range Android over 4G (one `/home` request).
- Web pages: first HTML < 1 s from server, total page weight on mobile < 1 MB, no build step, no heavy frameworks.
- Card images ≈ 30 KB WebP thumbnails; detail images ≈ 150 KB; lists paginated by 20; images lazy-loaded.

---

## 7. System architecture

```
                         ┌───────────────────────────────── C:\xampp\htdocs\chimbo ─────────────────────────────────┐
┌──────────────────┐     │                                                                                          │
│ Flutter app      │     │   api/index.php  ──▶ Router ─▶ Middleware ─▶ Controllers ─┐                             │
│ (Android, later  │────▶│   (JSON, Bearer token)                                     │                             │
│  iOS)            │     │                                                            ▼                             │
└──────────────────┘     │   Web storefront pages (*.php) ─── render with ───▶  src/Services  ─▶ src/Repositories ─┼─▶ MySQL
┌──────────────────┐     │     + assets/js (fetch → api/, session cookie + CSRF)      ▲   (business rules)         │   (InnoDB,
│ Browser          │────▶│                                                            │                             │    utf8mb4)
│ (customers)      │     │   admin/*.php (Bootstrap, admin session + CSRF) ───────────┘                             │
└──────────────────┘     │                                                                                          │
┌──────────────────┐     │   api/v1/webhooks/payments/{provider}  ◀── payment aggregator (M-Pesa/Airtel/Mixx)       │
│ Browser (staff)  │────▶│   cron/*.php  (reconcile payments, expire unpaid orders, send SMS)                        │
└──────────────────┘     │   media/  (product images, WebP)          outbound: payment aggregator, SMS gateway, FCM  │
                         └──────────────────────────────────────────────────────────────────────────────────────────┘
```

Principles:
- **The backend is the only source of truth** for prices, stock, totals, order status and payment status. App and website display; they never decide.
- **Business rules are written once** in `src/Services`. The API, the web pages and the admin all call the same Services.
- **All third-party secrets stay on the server** (`.env`).
- **One versioned JSON API** (`/api/v1`) used by the mobile app and by the website's JavaScript.
- **No extra infrastructure**: no Redis, no queues, no Docker, no Node build tools. MySQL tables + cron cover rate limits, jobs and reconciliation.

---

## 8. Flutter (mobile app) architecture

Location: **`C:\Users\edgar\AndroidStudioProjects\chimbo\`** (created with `flutter create` inside the existing empty folder, org `com.chimbo`, Android + iOS platforms).

### 8.1 Packages (kept small)
| Package | Purpose |
|---|---|
| `flutter_riverpod` | State management — shared cart count, auth state, orders; `AsyncValue` = loading/error/data. No code generation. |
| `go_router` | Routing, auth redirects, `StatefulShellRoute` for the 5 bottom tabs |
| `dio` | HTTP client + interceptors (token, language, error mapping) |
| `flutter_secure_storage` | Auth token (Android Keystore / iOS Keychain) |
| `shared_preferences` | Onboarding-seen flag, language |
| `cached_network_image` | Image cache + placeholders |
| `intl`, `flutter_localizations` | sw/en strings (gen-l10n, like Zala Salama), TZS formatting |
| `google_fonts` (fonts bundled) | Typography |
| `url_launcher`, `share_plus`, `photo_view`, `skeletonizer` | Call/WhatsApp, share, zoom, skeletons |
| v1.1: `firebase_messaging`, `firebase_crashlytics` | Push, crash reports |

JSON models written by hand (`fromJson`/`toJson`).

### 8.2 Folder structure (feature-first)
```
AndroidStudioProjects\chimbo\lib\
├── main.dart                   # bootstrap, ProviderScope
├── app.dart                    # MaterialApp.router, theme, locale
├── core\
│   ├── config\env.dart         # API_BASE_URL via --dart-define
│   ├── network\                # api_client.dart, api_exception.dart, api_response.dart
│   ├── storage\token_storage.dart
│   ├── router\app_router.dart
│   ├── theme\                  # app_colors.dart, app_text.dart, app_theme.dart
│   ├── l10n\                   # app_sw.arb (default), app_en.arb
│   ├── utils\                  # money.dart, phone.dart, dates.dart
│   └── widgets\                # shared UI kit (8.4)
└── features\
    ├── onboarding\  auth\  home\  catalog\  search\  wishlist\  cart\
    ├── checkout\  payments\  orders\  profile\  notifications\
    └── (each feature) data\ (api, repository, models) · application\ (Riverpod controllers) · presentation\ (screens, widgets)
```
**Widgets never call Dio and never contain business rules.**

### 8.3 Key flows
- **Auth:** `authController` state = `unknown | unauthenticated | needsProfile | authenticated`; router redirects on it; Dio adds `Authorization: Bearer`; a `401` clears the token → phone screen.
- **Cart:** every change returns the full priced cart from the server; the app shows it. (The product page may *preview* tier prices from the tiers the server sent; the binding numbers always come from the server.)
- **Checkout:** `CheckoutController` holds selections; Review calls `/checkout/preview`; confirm calls `POST /orders` with one idempotency key per checkout.
- **Payment waiting:** poll `GET /payments/{id}` every 3 s (max ~2 min) until final.

### 8.4 Shared UI kit
`AppButton`, `AppTextField`, `PhoneField(+255)`, `OtpInput(6)`, `AppNetworkImage`, `ProductCard`, `PriceText`, `TierTable`, `QuantityStepper(min: MOQ)`, `VerifiedBadge`, `StatusChip`, `StepProgress`, `EmptyState`, `ErrorState`, `SkeletonGrid`, `CartIconBadge`, `SectionHeader`, `AppBottomSheet`.

---

## 9. PHP backend architecture

Location: **`C:\xampp\htdocs\chimbo\`**.

### 9.1 Style — SCMRS classes + helpers + API files (not full MVC)
Agreed with the user (v2.1): no Controller/Service/Repository layers. Instead:

| Part | Folder | Role |
|---|---|---|
| **Domain classes** | `classes/` | One class per part of the shop (`User`, `Otp`, `Product`, `Category`, `Cart`, `Order`, `Payment`, `Notification` …). Like SCMRS: the class holds its SQL, input validation and business rules; the DB connection comes in through the constructor. Used by the API, the website and the admin. |
| **Helpers** | `classes/core/` and `classes/` | Reusable tools that keep domain classes short: `Database`, `Validator`, `Request`, `Response`, `Router`, `ApiException`, `Logger`, `Env`, `Phone`; later `Money`, `Pricing` (tier maths), `ImageUploader`, `RateLimiter`, `Session`, `Csrf`. |
| **Payment & SMS providers** | `classes/payments/`, `classes/sms/` | Gateway interface + one class per provider (see §13). |
| **API files** | `api/endpoints/*.php` | One file per module; each endpoint is a short block: read the request → call a class method → return `Response`. Loaded by `api/index.php`. |

Compared with SCMRS: same class style, plus PDO with named placeholders, an autoloader (no `require_once`), `.env` for secrets, and one API entry point with consistent JSON errors. Composer is used only for tools/libraries (PHPUnit, Dompdf), not for our own classes.

> Wherever later sections mention "Controller", "Service" or "Repository", read it as: the endpoint block in the API file (controller) and the domain class (service + repository).

### 9.2 Folder structure
```
C:\xampp\htdocs\chimbo\
├── .htaccess  .env (not committed)  .env.example  bootstrap.php  composer.json  phpunit.xml
├── index.php  category.php  product.php  cart.php  checkout.php  …   ← WEB STOREFRONT (§10, Phase 7)
├── includes\          web layout partials (blocked)
├── assets\            css\ js\ img\ fonts\ vendor\
├── api\
│   ├── .htaccess      everything → api/index.php
│   ├── index.php      API entry point: loads every file in endpoints/, runs the router, always answers JSON
│   └── endpoints\     API FILES (blocked from direct access): system.php, auth.php, profile.php, catalog.php,
│                      cart.php, orders.php, payments.php, webhooks.php …
├── admin\             ADMIN pages (§14): includes\ ajax\ assets\ + index.php, products.php, orders.php …
├── classes\           (blocked)
│   ├── User.php  Otp.php  AuthToken.php  Region.php  Product.php  Category.php  Seller.php  Cart.php
│   ├── Wishlist.php  Address.php  Order.php  Notification.php  Admin.php  AuditLog.php  Settings.php  Phone.php …
│   ├── core\          Env, Database, Request, Response, Router, Validator, ApiException, Logger
│   │                  (+ AuthMiddleware, RateLimiter, Session, Csrf, Money, Pricing, ImageUploader as needed)
│   ├── payments\      PaymentGateway (interface), PaymentService, SandboxGateway, CashOnDeliveryGateway, <Aggregator>Gateway
│   └── sms\           SmsGateway (interface), LogSmsGateway, <Provider>SmsGateway
├── config\            (blocked)
├── database\          migrations\ seeds\ migrate.php (blocked)
├── cron\              reconcile_payments.php, expire_unpaid_orders.php, send_sms_queue.php (blocked)
├── media\             uploaded images (scripts never run)
├── storage\logs\      (blocked)
├── tests\             PHPUnit (blocked)
├── vendor\            Composer libraries (blocked)
└── docs\              blueprint, progress, standards (blocked)
```

### 9.3 Request lifecycle (API)
```
api/index.php → bootstrap.php → loads api/endpoints/*.php into the Router (under /v1)
  → middleware (e.g. AuthMiddleware: Bearer token from app, or session + CSRF from website)
  → endpoint block → domain class method (Validator::validate, SQL + rules, transaction) → Response::success(...)
Any exception → one handler → consistent JSON error (no stack traces in production)
```

### 9.4 Security in this style
Security comes from the rules (§15), not from the architecture. Three risks specific to this style and how they are prevented:
1. **An endpoint without a login check** → protected endpoints are declared inside an authenticated route group, so a new endpoint there is protected by default.
2. **Reading another user's data** → every class method that touches personal data takes `$userId` and filters with `WHERE user_id = :user_id`.
3. **Opening a class or API file directly in the browser** → `classes/` and `api/endpoints/` are blocked (tested: 403).

---

## 10. Web storefront architecture (PHP + JavaScript/AJAX)

Location: **root of `C:\xampp\htdocs\chimbo\`**. Built in **Phase 7** (default order).

### 10.1 How it works
1. **PHP renders every page** with real content from the Services (fast first load, readable by Google, works without waiting for JavaScript).
2. **Plain JavaScript `fetch()` calls the same `/api/v1` endpoints the app uses** for everything interactive — no full-page reloads for actions.
3. **Normal links** move between pages (a page load is fast; no single-page-app complexity).

| Done with AJAX (no reload) | Done with a normal page load |
|---|---|
| Add to cart, quick-add popover, change quantity, remove, cart badge | Opening Home, a category, a product, cart, checkout, orders, profile |
| Live tier price / "Unaokoa" as quantity changes | Login steps (phone → OTP → business) can be one page with AJAX steps |
| Sub-category chips, filters, sort, "load more" / infinite scroll (URL updated with `history.pushState` so links stay shareable) | |
| Wishlist heart, search suggestions | |
| Checkout steps (one page, 4 panels), payment waiting (polling), cancel/reorder | |
| Notifications badge, mark-as-read | |

### 10.2 Pages
```
index.php            Home (greeting when logged in; hero + categories + rails for guests)
category.php         /c/{slug}           listing with chips, filters, sort, load more
product.php          /p/{slug}           gallery, tiers, stepper, add to cart, share, wishlist
search.php           /search?q=
cart.php             Kikapu
checkout.php         Anwani → Usafirishaji → Malipo → Hakiki (one page, JS stepper)
payment.php          waiting for mobile-money confirmation (polling)
order_success.php    Oda Imepokelewa!
orders.php           Oda Zangu (tabs)          order.php  detail + Fuatilia Oda timeline
wishlist.php  notifications.php
account.php          Wasifu   · account_business.php · addresses.php · settings.php (language)
login.php            phone → OTP → business info (AJAX steps)       logout.php
help.php  terms.php  privacy.php  404.php
```
Pretty URLs (`/chimbo/p/vaseline-petroleum-jelly-400ml`) come from the root `.htaccess`.

### 10.3 JavaScript organisation (no build step, no frameworks)
```
assets/js/
├── chimbo.js        # api(method, path, body): adds X-CSRF-Token, parses the JSON envelope, shows toasts on errors,
│                    # handles 401 → login page; helpers: formatTZS(), debounce(), skeleton(), toast(), setLoading(btn)
├── cart.js          # add/update/remove, badge, quick-add popover (used on many pages)
├── catalog.js       # chips, filters, sort, load more, pushState
├── product.js       # gallery, stepper, tier preview, add to cart
├── checkout.js      # stepper, address/delivery/payment selection, preview, place order
├── payment.js       # status polling, retry
├── auth.js          # phone → OTP (resend timer) → business info
└── orders.js        # tabs, cancel, reorder
```
Bootstrap 5 + Bootstrap Icons served locally from `assets/vendor/`. Server-rendered HTML fragments are **not** used: the API returns JSON and small JS template functions build cards, so the API stays one format for both clients.

### 10.4 Web authentication
- **When:** guests shop freely; login is asked at checkout (and when opening orders, wishlist or profile). The guest cart lives in the browser (`localStorage`) and is sent to `POST /cart/merge` right after login.
- Same phone + OTP endpoints as the app. When the website calls `/auth/otp/verify` with `"client": "web"`, the server **starts a PHP session** (session id regenerated, cookie `HttpOnly`, `SameSite=Lax`, `Secure` in production) instead of returning a token.
- `AuthMiddleware` accepts **either** a Bearer token (app) **or** the customer session (website).
- Every state-changing AJAX request from the website sends the **CSRF token** (printed by PHP in `<meta name="csrf-token">`) in the `X-CSRF-Token` header — the SCMRS approach, adapted to AJAX.
- Customer sessions and admin sessions use **different cookie names** so they never mix.
- The website and API share the same domain, so **no CORS** is needed.

### 10.5 Layout
Mobile-first responsive Bootstrap: on phones the website looks like the app (bottom navigation, 2-column grid, sticky buy bar); on tablets/desktops it switches to a top header with search, 3–5 column grids and a side summary on cart/checkout.

---

## 11. MySQL database design

**Naming:** see `docs/CODING_STANDARDS.md` §3 — primary keys `<entity>_id`, columns prefixed with the entity (`user_phone`, `product_name`), foreign keys named like their target (`user_id`), plain `created_at`/`updated_at`. The table lists below are shorthand; **the migration files are the source of truth for exact column names.**

Conventions: InnoDB, `utf8mb4_unicode_ci`, `BIGINT UNSIGNED` ids, **money = `INT UNSIGNED` whole TZS**, timestamps stored in **UTC**, foreign keys enforced, soft delete (`deleted_at`) only on users and products. SQL kept compatible with local MariaDB 10.4 and production MySQL 8 / MariaDB 10.6+. Database name: **`chimbo`**.

### 11.1 MVP tables (by migration)

**001_core.sql — identity, auth, platform**
```
users              id, phone (E.164, UNIQUE), full_name, email NULL, avatar_path NULL, locale ('sw'|'en'),
                   status ('active'|'suspended'|'deleted'), phone_verified_at, last_login_at,
                   created_at, updated_at, deleted_at
business_profiles  id, user_id UNIQUE FK, business_name NULL, region_id FK, district_id NULL FK,
                   verification_status ('unverified'|'pending'|'verified'|'rejected'),
                   verified_at NULL, verified_by NULL FK admin_users, created_at, updated_at
otp_codes          id, phone, code_hash, purpose ('login'), attempts, expires_at, consumed_at NULL,
                   ip_address, created_at                       INDEX(phone, created_at)
auth_tokens        id, user_id FK, token_hash CHAR(64) UNIQUE, device_name, platform ('android'|'ios'),
                   expires_at, last_used_at, revoked_at NULL, created_at      (app only; web uses sessions)
rate_limits        rl_key, window_start, hits                   PK(rl_key, window_start)
regions            id, name                   (31 regions, seeded)
districts          id, region_id FK, name     (seeded)
admin_users        id, full_name, email UNIQUE, password_hash, role ('super_admin'|'catalog'|'operations'|
                   'finance'), status, failed_logins, locked_until NULL, last_login_at, created_at
audit_logs         id, actor_type ('admin'|'system'|'customer'), actor_id, action, entity_type, entity_id,
                   old_values JSON NULL, new_values JSON NULL, ip_address, created_at
settings           setting_key PK, setting_value, updated_at   (support phone/WhatsApp, COD limit, OTP TTL, legal texts)
sms_outbox         id, phone, message, purpose, status ('queued'|'sent'|'failed'), attempts,
                   provider_message_id NULL, created_at, sent_at NULL
migrations         id, filename, applied_at
```

**002_catalog.sql**
```
sellers            id, name, slug UNIQUE, description, logo_path, phone, is_verified, status, created_at
categories         id, parent_id NULL FK self, name_sw, name_en, slug UNIQUE, tagline ('Shamba la Vipodozi'),
                   image_path, sort_order, is_active
products           id, seller_id FK, category_id FK (leaf), name, slug UNIQUE, description, brand NULL,
                   sku UNIQUE, unit_label ('pc'|'pack'|'set'), moq ≥ 1, stock_qty, is_active, is_featured,
                   new_until NULL, compare_at_price NULL (Hot Deals), sold_count, rating_avg, rating_count,
                   delivery_days_min, delivery_days_max, created_at, updated_at, deleted_at
                   INDEX(category_id, is_active), INDEX(sold_count), FULLTEXT(name, brand, description)
product_price_tiers id, product_id FK, min_qty, unit_price     UNIQUE(product_id, min_qty)
product_images     id, product_id FK, path_thumb, path_medium, path_large, sort_order, is_primary
inventory_movements id, product_id FK, change_qty (+/−), reason ('restock'|'order_reserve'|'order_release'|
                   'adjustment'|'return'), reference_type, reference_id, admin_id NULL, created_at
banners            id, title_sw, title_en, subtitle_sw, subtitle_en, image_path, cta_label,
                   target_type ('category'|'product'|'collection'|'url'), target_value,
                   sort_order, is_active, starts_at NULL, ends_at NULL
```

**003_shopping.sql**
```
cart_items         id, user_id FK, product_id FK, quantity, created_at, updated_at   UNIQUE(user_id, product_id)
wishlist_items     user_id FK, product_id FK, created_at                              PK(user_id, product_id)
addresses          id, user_id FK, recipient_name, phone, region_id FK, district_id NULL FK, street,
                   landmark NULL, is_default, created_at, updated_at
delivery_methods   id, code ('standard'|'express'), name_sw, name_en, fee, eta_min_days, eta_max_days,
                   is_active, sort_order
```

**004_orders.sql**
```
orders             id, order_number UNIQUE ('CHB' + digits), user_id FK, channel ('app'|'web'),
                   status (11.3), payment_status ('unpaid'|'pending'|'paid'|'cod_pending'|'refunded'|'failed'),
                   payment_method ('mpesa'|'airtel'|'mixx'|'bank'|'cod'),
                   subtotal, delivery_fee, discount_total, grand_total, delivery_method_id FK,
                   ship_name, ship_phone, ship_region, ship_district, ship_street, ship_landmark,  (address snapshot)
                   estimated_delivery_date, customer_note NULL, idempotency_key, expires_at NULL,
                   placed_at, delivered_at NULL, cancelled_at NULL, cancel_reason NULL, created_at, updated_at
                   UNIQUE(user_id, idempotency_key), INDEX(user_id, status, created_at)
order_items        id, order_id FK, product_id FK, seller_id FK, product_name, sku, image_path, unit_label,
                   quantity, unit_price, tier_min_qty, line_total          (snapshot — never changes)
order_status_history id, order_id FK, status, note NULL, actor_type, actor_id NULL, created_at
delivery_agents    id, full_name, phone, photo_path, is_active
order_deliveries   id, order_id UNIQUE FK, agent_id NULL FK, dispatched_at, delivered_at,
                   cod_amount_collected NULL, cod_confirmed_by NULL FK admin_users
notifications      id, user_id FK, type ('order_status'|'payment'|'promo'|'system'), title, body,
                   data JSON, read_at NULL, created_at               INDEX(user_id, read_at)
```

**005_payments.sql**
```
payments           id, order_id FK, user_id FK, provider ('sandbox'|'cod'|'<aggregator>'),
                   method ('mpesa'|'airtel'|'mixx'|'bank'|'cod'), amount, currency ('TZS'),
                   status ('initiated'|'pending'|'successful'|'failed'|'cancelled'|'expired'|'refunded'),
                   reference UNIQUE (ours), provider_reference NULL, payer_phone NULL, idempotency_key,
                   failure_reason NULL, initiated_at, completed_at NULL, created_at, updated_at
                   UNIQUE(provider, provider_reference), INDEX(order_id, status), INDEX(status, created_at)
payment_events     id, payment_id NULL FK, provider, event_type ('initiate_request'|'initiate_response'|
                   'webhook'|'status_query'|'manual'), http_status NULL, signature_valid NULL,
                   payload JSON (secrets removed), created_at          (append-only)
```

Admin roles = a column + a permission map in `src/Admin/Permissions.php` (simpler than role tables for 4 roles). Web customer sessions use PHP's normal session files (no table needed).

### 11.2 Relationships
```
users 1─1 business_profiles · users 1─* addresses, auth_tokens, cart_items, wishlist_items, orders, notifications
regions 1─* districts · sellers 1─* products · categories 1─* categories (children), products
products 1─* product_images, product_price_tiers, inventory_movements
orders 1─* order_items, order_status_history, payments · orders 1─1 order_deliveries
payments 1─* payment_events · delivery_agents 1─* order_deliveries
```

### 11.3 Order status machine
```
pending_payment ──paid──▶ confirmed ─▶ packed ─▶ dispatched ─▶ in_transit ─▶ delivered
     │                  (Imethibitishwa) (Imepakiwa) (Imetumwa) (Inasafirishwa) (Imewasili)
     │  COD orders start at confirmed with payment_status = cod_pending
     ├─ timeout/failed ─▶ expired     (stock released)
     └─ customer/admin ─▶ cancelled   (only before packed; stock released; refund if paid)
```
Enforced in `OrderService::transition()`. Tabs: *Zinazoendelea* = pending_payment…in_transit · *Zimefika* = delivered · *Zote* = all.

### 11.4 Deferred tables
| Table | Version | Reason |
|---|---|---|
| `reviews` (+ show ratings) | v1.1 | Needs delivered orders; ratings hidden until real reviews exist |
| `coupons`, `promotions` | v1.1 | "Punguzo" rule not defined |
| `device_tokens` | v1.1 | Push notifications |
| `refunds` | v1.1 | v1 refunds manual + logged in `payment_events` |
| `search_logs`, `business_documents` | v1.1 | Search insights, document verification |
| `product_variants`, `product_attributes` | v2 | No variants in the mocks |
| `seller_users`, `seller_payouts`, `sub_orders` | v2 | Multi-vendor self-service |
| `conversations`, `messages` | v2 | In-app chat |
| `delivery_locations` | v2 | Live map |

---

## 12. API architecture

### 12.1 Conventions
- Base: `http://localhost/chimbo/api/v1` (local) · `https://<domain>/api/v1` (production).
- Headers: `Authorization: Bearer <token>` (app) **or** session cookie + `X-CSRF-Token` (web) · `Accept-Language: sw|en` · `Idempotency-Key` on order/payment creation.
- Success: `{ "success": true, "data": {…}, "meta": { "page": 1, "per_page": 20, "total": 134, "last_page": 7 } }`
- Error: `{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…", "fields": { "phone": "…" } } }`
- Status codes: 200/201 · 401 · 403 · 404 · 409 (price/stock changed, duplicate) · 422 · 429 · 500.
- Error codes: `VALIDATION_ERROR, UNAUTHENTICATED, CSRF_INVALID, OTP_INVALID, OTP_EXPIRED, OTP_TOO_MANY_ATTEMPTS, RATE_LIMITED, NOT_FOUND, MOQ_NOT_MET, OUT_OF_STOCK, PRICE_CHANGED, ORDER_NOT_PAYABLE, PAYMENT_IN_PROGRESS`.
- **JSON keys = database column names** (`user_id`, `user_phone`, `product_name`, `order_total` …); computed values use the same style (`product_price_from`). The field names in the tables below are shorthand.
- Pagination `?page=&per_page=` (max 50). Money = integer TZS. Dates = ISO-8601 UTC. Images = `{thumb, medium, large}` absolute URLs.

### 12.2 Endpoints
Auth: **P** public · **O** optional login · **A** logged-in customer (token or session) · **S** signed webhook.

#### Auth & locations (Phase 1)
| Method | Endpoint | Auth | Purpose | Key params | Response | Tables |
|---|---|---|---|---|---|---|
| POST | `/auth/otp/request` | P | Send OTP (also "Tuma tena") | `phone` | `{expires_in, resend_after}` | otp_codes, rate_limits, sms_outbox |
| POST | `/auth/otp/verify` | P | Verify OTP; create user if new | `phone, code, client ("app" or "web"), device_name?, platform?` | app: `{token, expires_at, user, profile_complete}` · web: session started, `{user, profile_complete}` | otp_codes, users, auth_tokens |
| POST | `/auth/profile` | A | Registration step 3 | `full_name, business_name?, region_id, district_id?` | `{user}` | users, business_profiles |
| GET | `/auth/me` | A | Current user + counts | — | `{user, business, cart_count, unread_notifications}` | users, business_profiles |
| POST | `/auth/logout` | A | Revoke token / destroy session | — | `{}` | auth_tokens |
| GET | `/regions` | P | Mkoa list | — | `[{id, name}]` | regions |
| GET | `/regions/{id}/districts` | P | Wilaya list | — | `[{id, name}]` | districts |

`/auth/otp/request` answers the same way whether the number exists or not.

#### Profile (Phase 1)
| Method | Endpoint | Auth | Purpose | Key params | Response | Tables |
|---|---|---|---|---|---|---|
| GET | `/me` | A | Wasifu data | — | user + business | users, business_profiles |
| PATCH | `/me` | A | Edit personal info / language | `full_name?, email?, locale?` | user | users |
| POST | `/me/avatar` | A | Upload photo (multipart) | `avatar` ≤ 3 MB jpg/png/webp | `{avatar_url}` | users |
| PATCH | `/me/business` | A | Taarifa za Biashara | `business_name?, region_id?, district_id?` | business | business_profiles |
| DELETE | `/me` | A | Delete account (anonymise; keep order records) | `confirm: true` | `{}` | users, auth_tokens |

#### Home & catalog (Phase 2)
| Method | Endpoint | Auth | Purpose | Key params | Response | Tables |
|---|---|---|---|---|---|---|
| GET | `/home` | O | Everything Home needs in one call | — | `{banners, top_categories, deals, new_arrivals, best_sellers, recently_ordered}` | banners, categories, products, order_items |
| GET | `/categories` | P | Category tree | — | `[{id, name, tagline, image, children[]}]` | categories |
| GET | `/categories/{id}` | P | Category + chips | — | category + children | categories |
| GET | `/products` | O | Listing / search / collections | `category_id, q, collection (deals / new / best_sellers), min_price, max_price, max_moq, sort (popular / newest / price_asc / price_desc), page, per_page` | paginated cards `{id, slug, name, image, price_from, moq, unit, badge, seller{name, verified}, in_stock, is_wishlisted}` | products, product_price_tiers, product_images, sellers, wishlist_items |
| GET | `/products/{id}` | O | Product detail (id or slug) | — | images[], tiers[], moq, stock_display, delivery eta, seller, description, is_wishlisted | products + related |
| GET | `/products/{id}/related` | O | "You may also like" | `limit` | cards | products |
| GET | `/search/suggestions` | P | Type-ahead | `q` | `[{type, label, id}]` | products, categories |

#### Wishlist, cart, addresses (Phase 3)
| Method | Endpoint | Auth | Purpose | Key params | Response | Tables |
|---|---|---|---|---|---|---|
| GET | `/wishlist` | A | Saved products | `page` | cards | wishlist_items, products |
| POST | `/wishlist` | A | Save | `product_id` | `{}` | wishlist_items |
| DELETE | `/wishlist/{product_id}` | A | Remove | — | `{}` | wishlist_items |
| GET | `/cart` | A | Priced cart | — | `{groups[{category, items[{product, quantity, unit_price, tier_label, line_total, moq, moq_met, in_stock, next_tier_hint}]}], summary{line_count, piece_count, subtotal, savings}, warnings[]}` | cart_items, products, product_price_tiers |
| POST | `/cart/items` | A | Add (increments) | `product_id, quantity` | full cart | cart_items |
| PATCH | `/cart/items/{product_id}` | A | Set quantity | `quantity` | full cart | cart_items |
| DELETE | `/cart/items/{product_id}` | A | Remove | — | full cart | cart_items |
| DELETE | `/cart` | A | Empty cart | — | empty cart | cart_items |
| GET | `/addresses` | A | List | — | `[address]` | addresses |
| POST | `/addresses` | A | Create | `recipient_name, phone, region_id, district_id?, street, landmark?, is_default?` | address | addresses |
| PATCH | `/addresses/{id}` | A | Update (owner only) | same, optional | address | addresses |
| DELETE | `/addresses/{id}` | A | Delete | — | `{}` | addresses |

#### Checkout, orders, notifications, support (Phase 4)
| Method | Endpoint | Auth | Purpose | Key params | Response | Tables |
|---|---|---|---|---|---|---|
| GET | `/checkout/options` | A | Delivery + payment methods | — | `{delivery_methods[], payment_methods[]}` | delivery_methods, settings |
| POST | `/checkout/preview` | A | Hakiki totals (server-computed) | `address_id, delivery_method_id, payment_method` | `{items, subtotal, delivery_fee, discount_total, grand_total, estimated_delivery, problems[]}` | cart_items, products, delivery_methods |
| POST | `/orders` | A + Idempotency-Key | Thibitisha Oda | `address_id, delivery_method_id, payment_method, expected_total, note?` | `{order, next_action: "pay" or "none"}` · 409 `PRICE_CHANGED` | orders, order_items, order_status_history, products, inventory_movements, cart_items |
| GET | `/orders` | A | Oda Zangu | `group (active / delivered / all), page` | paginated | orders, order_items |
| GET | `/orders/{id}` | A | Detail (owner only) | — | order + items + payment | orders, order_items, payments |
| GET | `/orders/{id}/tracking` | A | Fuatilia Oda | — | `{status, banner, timeline[], eta, agent{name, phone, photo}}` | order_status_history, order_deliveries, delivery_agents |
| POST | `/orders/{id}/cancel` | A | Cancel before packing | `reason?` | order | orders, inventory_movements, payments |
| POST | `/orders/{id}/reorder` | A | Agiza Tena | — | `{cart, skipped_items[]}` | cart_items, order_items, products |
| GET | `/orders/{id}/receipt` | A | Pakua Risiti | — | PDF | orders, order_items, payments |
| GET | `/notifications` | A | List | `page` | paginated | notifications |
| GET | `/notifications/unread-count` | A | Bell badge | — | `{count}` | notifications |
| POST | `/notifications/{id}/read` | A | Mark read | — | `{}` | notifications |
| POST | `/notifications/read-all` | A | Mark all read | — | `{}` | notifications |
| GET | `/support` | P | Msaada contacts + FAQ | — | `{phone, whatsapp, hours, faqs[]}` | settings |
| GET | `/legal/{page}` | P | `terms` or `privacy` | — | `{title, html}` | settings |

#### Payments (Phase 5)
| Method | Endpoint | Auth | Purpose | Key params | Response | Tables |
|---|---|---|---|---|---|---|
| POST | `/payments/initiate` | A + Idempotency-Key | Start mobile-money payment | `order_id, method, payer_phone` | `{payment_id, reference, status: "pending", instructions}` | payments, payment_events |
| GET | `/payments/{id}` | A | Poll status | — | `{status, order_status, failure_reason?}` | payments |
| GET | `/me/payments` | A | Payment history | `page` | paginated | payments, orders |
| POST | `/webhooks/payments/{provider}` | S | Provider callback | raw body + signature | provider ack | payment_events, payments, orders |
| POST | `/devices` (v1.1) | A | Register FCM token | `token, platform` | `{}` | device_tokens |

### 12.3 Pricing rules (`PricingService` — the only place prices are calculated)
1. Tiers ordered by `min_qty`; `unit_price` = highest tier with `min_qty ≤ quantity`.
2. `line_total = unit_price × quantity`; `savings = (base_price − unit_price) × quantity` → "Unaokoa TZS …".
3. `next_tier_hint = {add_qty, unit_price}` if a better tier exists.
4. `quantity < moq` → `MOQ_NOT_MET` (checkout blocked). `quantity > stock_qty` → `OUT_OF_STOCK`.
Used by cart, preview, order creation, web pages, admin and receipts.

### 12.5 PIN login (built, R-23)
**Registration:** phone → SMS code → **Tengeneza PIN** (enter + confirm) → business details → Home.
**Login:** phone → **PIN** (with "Umesahau PIN?") → Home.
**Umesahau PIN?:** SMS code → new PIN + confirm → Home. **Badilisha PIN** (Wasifu): current PIN + new PIN.

Rules (`CustomerPin`, `CustomerAuth`):
1. `POST /auth/start` answers `pin`, `pin_locked` or `otp` (and sends the code). It shows whether a number is registered — accepted, like WhatsApp/M-Pesa — so it is limited to 30 numbers per hour per device.
2. Stored as bcrypt of an HMAC keyed with `APP_KEY`. Changing `APP_KEY` makes every PIN stop working (customers then use "Umesahau PIN?").
3. 5 wrong PINs in a row **lock** the PIN; only a new PIN after an SMS code unlocks it. A right PIN resets the counter.
4. Refused PINs: one repeated digit, counting up/down (`1234`, `9876`, `123456`), the end of the customer's phone number.
5. A device that logged in with an SMS code may set a new PIN without the old one once, within 15 minutes (`auth_tokens.auth_token_pin_reset_until` / the web session). A stolen phone that is already logged in cannot change the PIN without the current PIN.
6. Replacing a PIN logs out every other device: app tokens are revoked; website sessions older than `users.user_sessions_revoked_at` stop working.
7. Staff can force a reset (lock + log out everywhere) but can never see or set a PIN.

### 12.4 Time-limited offers — "Ofa" (built 2026-10-08, R-22)
Backend: migration `010_product_offers.sql`, `ProductOffer`, `Pricing::withOffer()` / `offerPrice()` (API_REFERENCE "Product card").

**What the customer sees:** an "Ofa −15%" badge on the card, the old price crossed out next to the offer price, and a countdown on the product page ("Inaisha baada ya saa 5"). A Home rail "Ofa za muda" lists running offers.

**Rules**
1. One offer per product at a time: a **percentage** (1–90) taken off **every tier**, so bulk prices still get cheaper with quantity. Prices are rounded to whole shillings.
2. Each offer has a start and an end date-time (entered in East Africa time, stored in UTC). It starts and ends by itself — no cron job, the server compares the time on every request.
3. The server alone decides whether an offer is running. `Pricing::priceLine` applies it, so cart, checkout, orders, receipts, website and admin all agree.
4. If an offer ends while a customer is checking out, the existing `PRICE_CHANGED` check stops the order and shows the new total. The order copies the price actually charged, so it never changes afterwards.
5. The stored list prices (`product_price`, `product_price_from`) stay the normal prices; the listing works out the offer price for the page it returns.
6. Not in the first version: quantity limits ("first 100 pieces"), a limit per customer, coupon codes, offers on a whole category.

**Build list**
- Migration: `product_offers` (`product_offer_id`, `product_id`, `product_offer_percent`, `product_offer_starts_at`, `product_offer_ends_at`, `created_by_admin_id`, timestamps; index on `product_id, product_offer_ends_at`).
- Backend: `ProductOffer` class (create / end early / list for the admin, with audit log), `Pricing` takes the running percentage, product cards and the product page gain `product_offer_percent`, `product_offer_ends_at` and the offer price; `collection=offers` for the Home rail and Gundua.
- Admin: an "Ofa" box on the product form (percent, start, end) and an offers list.
- App and website: badge, crossed-out price, countdown, Home rail.
- Tests: offer running / not started / ended, all tiers discounted, `PRICE_CHANGED` when an offer ends mid-checkout.

---

## 13. Payment architecture

### 13.1 Requirements → design
| Requirement | Design |
|---|---|
| Secrets server-side | Only in `.env`; app/website know only `payment_id` and status |
| Payment records & statuses | `payments` row per attempt, strict state machine |
| References | Our unique `reference` (e.g. `CHBP-<order>-<n>`) + unique `provider_reference` |
| Callbacks | `POST /api/v1/webhooks/payments/{provider}` → verified → processed once |
| Server-side verification | Signature check + amount/currency match + (where supported) status query to the provider |
| No duplicates | Idempotency keys, one active attempt per order, unique constraints, row locks |
| Users can't mark paid | No endpoint accepts a status from app/web; only `PaymentService` (webhook/reconcile) or an authorised admin (audited) |
| Audit trail | `payment_events` (append-only) + `audit_logs` |
| New providers later | `PaymentGatewayInterface` + factory + config mapping |

### 13.2 Components
```php
interface PaymentGatewayInterface {
    public function code(): string;                                  // 'sandbox', 'cod', '<aggregator>'
    public function supports(string $method): bool;                  // 'mpesa', 'airtel', 'mixx', …
    public function initiate(InitiateRequest $req): InitiateResult;  // USSD push to payer phone
    public function queryStatus(array $payment): StatusResult;       // server-to-server check
    public function verifyWebhook(array $headers, string $rawBody): bool;
    public function parseWebhook(string $rawBody): WebhookEvent;     // {reference, status, amount, provider_ref}
}
```
- `PaymentGatewayFactory` reads `config/payments.php` (`'mpesa' => 'aggregatorX', …, 'cod' => 'cod'`). New provider = new Gateway class + config line; checkout untouched.
- `SandboxGateway` simulates success, failure, timeout and duplicate webhooks for development and tests.
- `CashOnDeliveryGateway` confirms orders with `payment_status = cod_pending`.
- `PaymentService` is the **only** class that changes payment state and order payment state.

**Provider strategy:** reach M-Pesa, Airtel Money and Mixx by Yas through **one licensed Tanzanian aggregator** (evaluate AzamPay, Selcom, ClickPesa, Pesapal, DPO — fees, settlement, sandbox, webhook signing, onboarding documents). Start the application early (D-10).

### 13.3 Mobile-money flow
```
App/Web                      Backend                                         Aggregator
 POST /orders ─────────────▶ order: pending_payment, stock reserved, expires_at = +30 min
 POST /payments/initiate ──▶ TXN: lock order; checks; insert payment 'initiated'; COMMIT
                             gateway.initiate() (outside the transaction) ───────────▶ USSD push
                             payment → 'pending', save provider_reference, log events
 ◀── {payment_id, pending}   (customer enters PIN on phone)
 poll GET /payments/{id}                        ◀──── POST /webhooks/payments/x ──────┘
                             1 save raw event  2 verify signature
                             3 TXN: SELECT payment FOR UPDATE; if final → ack only
                               amount & currency must match; optional queryStatus()
                               payment successful → order paid + confirmed → history → notification
                               COMMIT
 ◀── poll: successful → "Oda Imepokelewa!"
```
Initiation checks: order belongs to user · status `pending_payment` and not expired · **amount from the order, never the request** · no successful payment exists · a pending attempt < 2 min old is returned instead of creating another · same `Idempotency-Key` returns the same payment.

### 13.4 States
`initiated → pending → successful → (refunded)` · `pending → failed | cancelled | expired`. Final states never change except successful → refunded by Finance (audited).

### 13.5 Reliability (cron)
- `reconcile_payments.php` (2–5 min): query provider for pending payments older than 2 min; apply via the same method as the webhook.
- `expire_unpaid_orders.php` (5 min): expire unpaid orders, release stock, notify.
- Late success after expiry: record the payment, flag the order for Operations (re-confirm or refund) — never lose a payment silently.

### 13.6 Cash on delivery
Allowed per settings (e.g. max amount, verified businesses only — D-11). Operations records cash collected → `payments` row (`cod`, successful) + `payment_events` (`manual`) + order paid. Audited.

### 13.7 Webhook hardening
HTTPS · signature verified on the raw body · optional IP allow-list · fast response · idempotent · reference + amount must match · everything logged with secrets removed.

---

## 14. Admin architecture

Location: **`C:\xampp\htdocs\chimbo\admin\`** — PHP + Bootstrap 5, page-per-file like SCMRS; pages call the same Services as the API.

- **Login:** email + password (`password_hash`), sessions (HttpOnly, SameSite, Secure in production), session id regenerated on login, lockout after failed attempts, **CSRF on every POST** (SCMRS `csrf.php` approach), inactivity timeout.
- **Permissions:** role → permission map; every page/action calls `Permissions::require('orders.update')`.
- **Tables:** DataTables with server-side JSON from `admin/ajax/` (as in SCMRS).
- **Audit:** every change and every money/status action → `audit_logs`.

| Module | MVP | Later |
|---|---|---|
| Dashboard | Today's orders, revenue, pending payments, low stock | Charts |
| Categories | CRUD, tagline, image, order, sub-categories | — |
| Sellers | CRUD, verified toggle | Seller accounts |
| Products | CRUD, tiers editor, MOQ, image upload/reorder/primary, active/featured/deal | CSV import, variants |
| Inventory | Stock adjust with reason (logged), low-stock threshold | Warehouses |
| Banners | CRUD, schedule, target | — |
| Orders | Filter, view, advance status, assign agent, cancel, packing slip | Bulk actions |
| Delivery agents | CRUD | Agent app |
| Payments | List, detail + event log, "query provider", COD confirm, refund mark | Auto refunds |
| Customers | List, orders, verify business, suspend | Segments |
| Reports | Sales by day/category/product, payments by method, CSV | Advanced |
| Settings | Support contacts, delivery fees, COD rules, OTP limits, legal texts | — |
| Admin users | CRUD + roles (super admin) | 2FA |
| Audit log | Search | — |

---

## 15. Security considerations

**Secrets & transport** — HTTPS in production (HSTS); `.env` blocked by `.htaccess` and never committed; private folders blocked (`Require all denied`); the Flutter app and JavaScript contain **no secrets**.

**Customer authentication** — OTP: 6 random digits, stored hashed, 5-min TTL, max 5 attempts, single use, 60 s resend cooldown, per-phone and per-IP limits, numbers normalised to `+255[67]XXXXXXXX`. App tokens: 32 random bytes, stored as SHA-256 hash, 60-day sliding expiry, revocable (opaque tokens, not JWT, so revocation is instant). Web: regenerated PHP session + CSRF header on every change.

**Authorization** — every query scoped to the owner (`WHERE id = ? AND user_id = ?`) against IDOR; admin permission checks everywhere.

**Input & data** — server-side validation of every field; never trust prices, totals, statuses, MOQ or stock from clients; PDO prepared statements only; whitelisted sort columns; output escaped with `htmlspecialchars` on web and admin; transactions + `SELECT … FOR UPDATE` for stock, orders and payments.

**Files** — real MIME check with `finfo`, size limits, re-encode with GD (removes EXIF/GPS and hidden payloads), random names, no PHP execution in `media/`.

**Abuse & operations** — rate limits (OTP, login, search, orders, payments); generic client errors with a request id, details only in server logs; `audit_logs`; daily encrypted off-server backups; least-privilege DB user; security headers on web and admin.

**Privacy** — collect only what's needed; account deletion anonymises the user but keeps required order records; publish Terms & Privacy; register with Tanzania's data-protection authority (confirm with an advisor).

---

## 16. MVP scope

**Mobile app** — splash + onboarding · phone/OTP login + business step · persistent login, logout, delete account · Home (greeting, search, category cards, quick actions, banner, Recently Ordered) · listing (chips, filter, sort, infinite scroll) · text search · product detail (gallery/zoom, seller, MOQ, stock, ETA, tiers, stepper, savings, share, wishlist) · wishlist · cart (tiers, MOQ, nudges, groups, edit) · addresses · 4-step checkout · M-Pesa / Airtel Money / Mixx via one aggregator + COD · payment waiting/failed/retry · success screen · orders (tabs, detail, timeline tracking + agent contact, cancel, reorder, receipt PDF) · notifications list + badge · Wasifu (business, addresses, payment history, help, language, terms/privacy) · Kiswahili + English · "Uliza Muuzaji" → WhatsApp/call to CHIMBO support.

**Web storefront** — the same features in a responsive PHP + JS website, plus public catalog pages for guests (onboarding slides replaced by a Home hero section).

**Admin** — login/roles, categories, sellers, products (tiers, images, stock), banners, orders & statuses, delivery agents, payments & COD, customers & verification, reports, settings, audit log.

**Before the first beta:** time-limited offers "Ofa" (§12.4).

**Not in MVP** (in the document, deferred on purpose): camera/image search · live map tracking · ratings display · in-app seller chat · "Punguzo" cart discounts/coupons · bank payment (unless the aggregator includes it).

---

## 17. Future features

| Version | Features |
|---|---|
| **v1.1** | Push notifications · reviews & ratings · coupons / "spend X save Y" (Punguzo) · bank payment · document-based business verification · search suggestions & logs · crash reporting/analytics · SMS order updates · refunds table |
| **v1.2** | Camera image search · "Because you ordered…" recommendations · back-in-stock / price-drop alerts · saved "usual order" lists · product variants |
| **v2** | Seller portal & multi-vendor (payouts, commission) · in-app chat · delivery-agent app + live map · credit / "Lipa baadaye" for verified shops · loyalty & referrals · iOS release |

---

## 18. Development phases

Each feature phase follows the same order: **backend → admin → mobile app**. The web storefront reuses everything in Phase 7.

| Phase | Name | Backend (`htdocs\chimbo`) | Admin (`htdocs\chimbo\admin`) | Mobile (`AndroidStudioProjects\chimbo`) | Exit criteria |
|---|---|---|---|---|---|
| **0** | Foundation | Folder skeleton, `.htaccess` blocks, `.env`, `bootstrap.php`, autoloader, Core classes, `api/index.php`, `GET /health`, migration runner, `001_core.sql`, seeds | Login, layout, permissions skeleton | `flutter create`, packages, theme, l10n, router with 5 tabs, ApiClient, UI kit v1 | App on emulator calls `/health` successfully; admin login works |
| **1** | Auth & profile | OTP (log SMS in dev), tokens + web sessions, profile/business, locations, logout, delete, rate limits | Customers list (read-only) | Splash, onboarding, phone, OTP, business info, auth persistence, Wasifu, language | Register → restart app still logged in → logout → login |
| **2** | Catalog | `002_catalog.sql`, MediaService (WebP), categories, products, tiers, `/home`, filters, search | Categories, sellers, products (tiers + images), stock, banners | Home, listing, filters/sort, search, product detail, skeleton/empty/error states | Product created in admin shows correctly in the app |
| **3** | Shopping | PricingService (+ tests), `003_shopping.sql`, cart, wishlist, addresses | — | Quick-add, cart, badge, wishlist, addresses | Tiers/MOQ/savings correct everywhere |
| **4** | Checkout & orders (COD) | `004_orders.sql`, checkout preview, OrderService (transactions, stock), tracking, cancel, reorder, notifications, receipts | Orders workflow, agents, COD confirm, packing slip | Checkout stepper, review, success, orders, tracking, reorder, receipt, notifications | COD order runs placed → delivered with correct stock & history |
| **5** | Payments | `005_payments.sql`, PaymentService, Sandbox gateway, webhook, crons, then real aggregator | Payments list/detail/events, reconcile, refund mark | Payment step, waiting/failed/retry, history | All sandbox scenarios pass; one real small payment per wallet |
| **6** | Admin completion | Report queries, exports | Dashboard KPIs, reports, verification, settings, audit viewer, admin users | — | Operations can run a full day from the admin |
| **7** | **Web storefront** | Session auth + CSRF on API (if not already), pretty URLs, SEO tags | — | — (web: layout, `chimbo.js`, all pages in §10.2) | Full purchase on the website; same account/cart/orders as the app |
| **8** | Polish & hardening | Real SMS, SMS order notices, index review, security review, backups, monitoring | UX clean-up | Animations, offline banner, accessibility, low-end device tuning, crash reporting | Checklist §20 passes |
| **9** | Beta & launch | Production server, live keys | Staff training | Play Store internal → closed beta (10–20 shops) → production | Public release |

---

## 19. Implementation order (for the coding agent)

Every step ends with something testable; no step starts until the previous one is reviewed.

**Phase 0 — Foundation**
1. `htdocs\chimbo`: `git init`, `.gitignore` (`.env`, `vendor/`, `media/*` except `.gitkeep`, `storage/logs/*`), `README.md`, move this blueprint into `docs\`.
2. Enable `extension=gd` in `C:\xampp\php\php.ini`, restart Apache; install Composer; create database `chimbo` (utf8mb4).
3. Folder skeleton from §9.2 with `Require all denied` `.htaccess` files; `.env.example` + `.env`; `bootstrap.php` (env, autoloader, error handler, timezone).
4. Core: `Env`, `Database` (PDO helper), `Request`, `Response`, `Router`, `ApiException` family, `Logger`; `api/.htaccess` + `api/index.php`; `routes/api.php`; `GET /api/v1/health`.
5. `Validator`; Composer + PHPUnit with first tests (Validator, Response).
6. `database/migrate.php` + `001_core.sql` + seeds (regions/districts, settings, first super admin).
7. Admin skeleton: login/logout, `Session`, `Csrf`, `AdminAuth`, `Permissions`, Bootstrap layout (sidebar/topbar), empty dashboard.
8. `AndroidStudioProjects\chimbo`: `flutter create --org com.chimbo --platforms android,ios .`; packages; theme tokens; fonts; l10n (sw/en); `env.dart`; `ApiClient`; `go_router` with 5-tab shell; UI kit v1; a debug screen calling `/health`.

**Phase 1 — Auth & profile**
9. `SmsGatewayInterface` + `LogSmsGateway` + `sms_outbox`.
10. `OtpService`, `TokenService`, `AuthService`, `RateLimiter`, `AuthMiddleware` (token + session); `/auth/*`, `/regions`, `/regions/{id}/districts`; tests for OTP rules.
11. Flutter: splash, onboarding (seen flag), phone, OTP (resend timer, change number), business info, auth controller + redirects.
12. `/me` endpoints (profile, avatar, business, delete) + Flutter Wasifu (design per D-4), language switch, logout, delete account.
13. Admin: customers list/detail (read-only).

**Phase 2 — Catalog**
14. `002_catalog.sql` + demo catalog seed (the products from the PDF, placeholder images).
15. `MediaService` (validate → WebP thumb/medium/large).
16. Admin: categories, sellers, products (tiers editor, image uploader), stock adjustments, banners.
17. API: `/categories`, `/products` (filters, sort, pagination, FULLTEXT), `/products/{id}`, `/related`, `/home`, `/search/suggestions`.
18. Flutter: Home, listing (chips, filter/sort sheets, infinite scroll), `ProductCard`, product detail (gallery, zoom, tiers, stepper, savings), search.

**Phase 3 — Shopping**
19. `PricingService` + unit tests (every tier boundary, MOQ, savings, next-tier hint).
20. `003_shopping.sql` + delivery methods seed; API: cart, wishlist, addresses.
21. Flutter: quick-add sheet, cart (groups, nudges, MOQ, edit), badge, wishlist, addresses.

**Phase 4 — Checkout & orders**
22. `004_orders.sql`.
23. `CheckoutService` (preview), `OrderService` (create in one transaction with row locks, stock reservation, order number, cart clear; transitions; cancel; reorder) + tests.
24. API: checkout, orders, tracking, notifications, support, legal; COD path end-to-end.
25. Flutter: checkout stepper, review, success, orders tabs, detail, tracking + call/WhatsApp, cancel, reorder, notifications + bell badge.
26. Admin: orders list/detail, status advance, agent assignment, COD confirmation, packing slip.
27. `ReceiptService` (Dompdf) + "Pakua Risiti".

**Phase 5 — Payments**
28. `005_payments.sql`.
29. Gateway interface, DTOs, factory, `SandboxGateway`, `PaymentService`, webhook controller, reconcile + expire crons; tests for success, failure, timeout, duplicate webhook, wrong amount, bad signature, late success, idempotency.
30. Flutter: payment initiation, waiting screen (polling), failed/retry/switch to COD, payment history.
31. Admin: payments list/detail with event timeline, "query provider", refund marking.
32. Real aggregator gateway in sandbox → end-to-end tests → go-live checklist.

**Phase 6 — Admin completion**
33. Dashboard KPIs, reports + CSV, business verification, settings, audit-log viewer, admin users.

**Phase 7 — Web storefront**
34. Web layout (`includes/`), `theme.css`/`app.css` from the brand tokens, `chimbo.js` (api wrapper, CSRF, toasts, skeletons), root `.htaccess` pretty URLs.
35. Login page (phone → OTP → business, AJAX) using session auth.
36. Home, category, search, product pages (server-rendered + `catalog.js`/`product.js`/`cart.js`).
37. Cart, checkout (one page, 4 steps), payment waiting, success.
38. Orders, order detail + tracking, wishlist, notifications, account pages, help/terms/privacy, 404; SEO titles/meta/Open Graph.

**Phase 8 — Polish & hardening**
39. Real SMS provider; SMS on order confirmed/dispatched.
40. Animations, offline handling, accessibility, low-end device profiling, image-size audit (app and web).
41. Security review (§15), DB index review (`EXPLAIN`), backups, monitoring, crash reporting.

**Phase 9 — Launch**
42. Staging deploy, Postman/Newman regression, closed beta, fixes, production release (web + Play Store).

---

## 20. Testing strategy

| Layer | Tool | What |
|---|---|---|
| PHP unit | PHPUnit | PricingService, OtpService, OrderService (totals, transitions, stock), PaymentService (all states, duplicates, wrong amount, bad signature, late success, idempotency) |
| PHP integration | PHPUnit + `chimbo_test` DB | Register → cart → order → pay (sandbox) → deliver |
| API contract | **Postman** collection (`docs/postman/`) + Newman | Success and error shapes for every endpoint |
| Concurrency | Script firing parallel orders on the last units | No overselling, no duplicate payments |
| Flutter unit/widget | `flutter_test` | Formatters, models, controllers (fake repos), ProductCard, TierTable, stepper (MOQ), cart summary, checkout stepper, empty/error states |
| Flutter integration | `integration_test` | Happy path against staging |
| Web | Manual checklist + browser dev tools | Chrome/Android, Safari/iPhone, desktop; slow-3G throttling; JS errors console-clean; works with a refresh at every step |
| Manual app | Test matrix | Low-end Android (2–3 GB RAM), mid-range, slow network, airplane mode, sw + en |
| Payments | Aggregator sandbox, then one small live payment per wallet | Before launch |
| Security | Checklist + OWASP API Top 10 | IDOR, CSRF, rate limits, validation, secrets, file uploads |

Rule: **every money-related bug gets a regression test before it is fixed.**

**Release checklist (Phase 8 exit):** all tests green · no PHP warnings in logs · every screen/page has loading/empty/error states · images WebP & lazy-loaded · rate limits on · `display_errors` off · backups restored once successfully · webhook signature verified · admin audit log populated.

---

## 21. Deployment strategy

**Environments:** local (XAMPP: `http://localhost/chimbo/`) → staging → production.

**Server requirements:** PHP 8.2+, MySQL 8 / MariaDB 10.6+, HTTPS, SSH, **real cron**, receives webhooks, outbound cURL, `.htaccess` (Apache) support, **no bot-protection page in front of the site**.
> ⚠️ Free hosts like InfinityFree (used for SCMRS) put a JavaScript challenge in front of every request — it **breaks mobile-app API calls and payment webhooks** — and restrict cron. Not suitable for CHIMBO. Check the host behind `kachimbo.efolder.fun` against this list (D-17). A small VPS or a quality managed PHP host is recommended.

**Domains (suggested):** `kachimbo.efolder.fun` → web storefront (replaces the current landing page) with `/api/v1` and `/admin` on the same domain (simplest; admin can later move to its own subdomain or be IP-restricted).

**Release process**
1. Two private GitHub repos: `chimbo-backend` (htdocs folder) and `chimbo-app` (Flutter). Branches: `main`, `develop`, feature branches.
2. Backend deploy: `git pull` over SSH → `composer install --no-dev` → `php database/migrate.php` → check `/api/v1/health`.
3. Cron: reconcile payments (2–5 min), expire unpaid orders (5 min), SMS queue (1 min), nightly backup.
4. Backups: nightly `mysqldump` + `media/`, encrypted, off-server; test a restore monthly.
5. Monitoring: uptime check on `/health`, error-log alerts, alarm if > 10 payments pending for > 15 min.
6. App: `--dart-define=API_BASE_URL=https://…/api/v1`; `flutter build appbundle --obfuscate --split-debug-info=…`; Play Console internal → closed → production. iOS later.

---

## 22. Technical risks and mitigations

| Risk | Mitigation |
|---|---|
| Payment aggregator onboarding takes weeks (business docs, TIN, bank) | Apply during Phase 0; build with `SandboxGateway`; launch with COD if needed |
| SMS sender-ID registration delay | Apply early; dev logs OTPs; generic sender for beta |
| Unsuitable hosting (JS challenge, no cron/webhooks) | Verify host before Phase 5; deploy staging early |
| Overselling | Transactions + `FOR UPDATE` + reservation + concurrency test |
| Fake/duplicate "paid" | §13: idempotency, unique refs, amount + signature + status query, no client status |
| Lost webhooks | Reconciliation cron + late-success handling |
| OTP SMS abuse | Per-phone/IP limits, TZ numbers only, cooldowns, daily cap alarm |
| Slow on cheap phones / costly data | WebP variants, pagination, caching, one `/home` call, no heavy JS, test on a low-end phone every phase |
| Two customer front-ends drift apart | Same API, same Services, same brand tokens; web built after the app so flows are settled |
| Scope creep (chat, live map, image search, multi-vendor) | §16 is the contract; new ideas go to §17 |
| Unclear business rules | Resolve §23 before the phase that needs them |
| No real product data/photos | Start collecting real photos, SKUs, tier prices, MOQs now |
| Learning curve (Riverpod, PDO, Composer) | Introduce each when needed, with short explanations; identical patterns across features |
| Local MariaDB 10.4 vs production MySQL 8 | Portable SQL; run migrations on staging early |
| Data-protection compliance | Privacy policy, minimal data, deletion, regulator registration |

---

## 23. Decisions and open questions

### Decided
| # | Decision |
|---|---|
| ✔ | Stack: Flutter · PHP 8.2 OOP (SCMRS style) · REST JSON · MySQL · PHP + Bootstrap admin |
| ✔ | Backend, admin, web storefront and docs in `C:\xampp\htdocs\chimbo\`; Flutter app in `C:\Users\edgar\AndroidStudioProjects\chimbo\` |
| ✔ | Web storefront = PHP pages + Bootstrap + plain JavaScript/AJAX calling the same API (no Flutter web, no JS frameworks) |
| ✔ | One API for app and web; app uses Bearer tokens, web uses sessions + CSRF |
| ✔ | **D-1: mobile app first** (Phases 1–5 client = Flutter); web storefront in Phase 7 |
| ✔ | **D-6 (2026-09-28): Web** — guests browse, see prices and add to cart; login at checkout; the guest cart (kept in the browser) is merged into the account cart after login via `POST /cart/merge` (backend, Phase 3). **App** — keeps the PDF flow: registration right after onboarding. |

### Open (default applies if no answer)
| # | Question | Needed by | Default |
|---|---|---|---|
| D-1b | Are "Shamba la Vipodozi" / "Mrembo Muuza Urembo" **seller names** or **category nicknames**? Does CHIMBO fulfil all orders in v1? | Phase 2 | Category taglines + seller records supported; CHIMBO fulfils |
| D-2 | Kiswahili default + English option? | Phase 0 | Yes |
| D-3 | Replace "Jaza stock. Kuza biashara." everywhere with "Agiza. Amini. Pokea. Kuza Biashara."? | Phase 1 | Yes |
| D-4 | Profile design p.10 or p.11? | Phase 1 | p.11 + p.10's verified badge |
| D-5 | After registration: straight to Home or a welcome step? | Phase 1 | Home with a one-time welcome card |
| D-7 | Rule for "Punguzo" and "Ongeza TZS 10,000 upate bei nzuri zaidi" | Phase 3 | No cart discount in MVP; per-item tier nudges |
| D-8 | MOQ is a hard minimum? | Phase 3 | Yes |
| D-9 | Delivery fees flat or by region? Regions served? | Phase 4 | Flat 5,000 / 10,000; Haraka only in Dar es Salaam |
| D-10 | Payment aggregator? Business registered (BRELA/TIN)? | Apply now; Phase 5 | Evaluate 2–3, choose one |
| D-11 | COD rules | Phase 4 | Allowed up to a configurable limit (e.g. TZS 300,000) |
| D-12 | How is a business verified? | Phase 6 | Admin toggle after a call |
| D-13 | Who delivers (in-house, boda-boda, courier)? | Phase 4 | In-house agents |
| D-14 | "Uliza Muuzaji" = WhatsApp to CHIMBO support in v1? | Phase 2 | Yes |
| D-15 | Defer camera search, live map, ratings display? | Phase 2 | Yes |
| D-16 | Logo source files, exact colours, font | Phase 0 | Approximations in §6.3 |
| D-17 | Production host/domain for `kachimbo.efolder.fun`? | Phase 5 | Same domain for web, `/api`, `/admin` |
| D-18 | Prices VAT-inclusive? VAT line on receipts? | Phase 4 | VAT-inclusive, no VAT line |

---

## 24. Step-by-step roadmap we follow together

Every phase uses the same loop:
1. **Agree** — re-read the phase, answer its open questions.
2. **Backend** — migration → repository → service (+ tests) → controller → route → test in Postman.
3. **Admin** — pages to create/manage that data.
4. **Client** — Flutter (Phases 1–5) or web pages (Phase 7): models → API calls → state → screens with loading/empty/error.
5. **Verify** — tests, click-through on emulator/real phone/browser, exit criteria (§18).
6. **Commit** — update this blueprint if anything changed, commit both repos, tag the phase.

| Step | Do | Result |
|---|---|---|
| 1 | Answer D-1, D-2, D-3, D-4, D-16 · start aggregator + SMS applications | `docs/decisions.md` |
| 2 | **Phase 0** — foundation | Emulator reaches `/health`; admin login; themed empty tabs |
| 3 | **Phase 1** — auth & profile | Register / login / logout / delete account |
| 4 | **Phase 2** — catalog | Admin products appear in the app |
| 5 | **Phase 3** — cart, wishlist, addresses | Correct tiers & MOQ |
| 6 | **Phase 4** — checkout & orders (COD) | Full order lifecycle |
| 7 | **Phase 5** — payments | Sandbox, then live mobile money |
| 8 | **Phase 6** — admin completion | Operations runs from the admin |
| 9 | **Phase 7** — web storefront | Buying on the website with the same account |
| 10 | **Phase 8** — polish & hardening | Fast, safe, polished |
| 11 | **Phase 9** — beta & launch | CHIMBO live on the web and the Play Store |

**Next action:** Phase 0, step 1.
