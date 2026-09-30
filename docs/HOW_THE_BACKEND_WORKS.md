# How the CHIMBO backend works

A plain-language guide to the backend: what an API is, and what happens — file by file — when the app talks to the server.
Updated as new parts are built.

---

## 1. What is an API?

In SCMRS, the browser asked for a **page** (`admin_dashboard.php`) and PHP sent back **HTML** to display.

The CHIMBO mobile app draws its own screens in Flutter. It doesn't want HTML — it wants **data**. So instead of pages, the backend offers **endpoints**: addresses that receive a request and answer with **JSON**.

```
SCMRS:   browser  ──GET admin_dashboard.php──▶  PHP  ──HTML page──▶  browser shows it
CHIMBO:  app      ──PATCH /api/v1/me/business─▶ PHP  ──JSON data──▶  app shows it in its own screen
```

A request has four parts:

| Part | Example | Meaning |
|---|---|---|
| **Method** | `GET`, `POST`, `PATCH`, `DELETE` | what to do: read, create, change, remove |
| **URL** | `/api/v1/me/business` | which thing |
| **Headers** | `Authorization: Bearer 7f3a…` | extra info, e.g. who is logged in |
| **Body** | `{"business_name": "Asha Cosmetics", "region_id": 16}` | the data sent |

The answer has a **status code** (`200` OK, `401` not logged in, `422` wrong input, `500` server error) and a **JSON body** — always one of two shapes:

```json
{ "success": true,  "data": { ... } }
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…", "fields": { "region_id": "Chagua mkoa sahihi." } } }
```

The same API serves the mobile app, the website's JavaScript and (later) anything else.

---

## 2. One request, step by step

Example: Joyce changes her shop's region in the app ("Taarifa za Biashara").

```
PATCH http://localhost/chimbo/api/v1/me/business
Authorization: Bearer 7f3a9c…
Content-Type: application/json

{"business_name": "Asha Cosmetics", "region_id": 16}
```

### Step 1 — Apache sends it to one file: `api/.htaccess`
There is no file called `me/business`. The rule in `api/.htaccess` sends **every** request under `/api/` to `api/index.php`. This is the "front controller": one entrance for the whole API.

### Step 2 — Setup: `api/index.php` → `bootstrap.php`
`bootstrap.php` runs first for every request:
- reads `.env` (database name, secrets) with `Env`
- registers the **autoloader**: when code says `new User(...)`, PHP finds `classes/User.php` by itself — no `require_once`
- turns PHP warnings into exceptions, so mistakes are never silently ignored

Then `api/index.php` gives the request an id (for the log) and prepares a safety net: whatever goes wrong later, the app still receives JSON.

### Step 3 — Read the request: `Request::fromGlobals()`
`classes/core/Request.php` collects everything into one object: method `PATCH`, path `/v1/me/business`, the JSON body decoded into a PHP array, the headers, and the token. From here on, nobody reads `$_POST` or `$_GET` directly.

### Step 4 — Load the endpoints: `api/endpoints/*.php`
`api/index.php` loads every API file (`system.php`, `auth.php`, `locations.php`, `profile.php` …). Each file only **registers** its endpoints with the Router — like writing entries in a phone book:

```php
// api/endpoints/profile.php
$router->group('/me', function (Router $router) {
    $router->patch('/business', function (Request $request) { ... });
}, [AuthMiddleware::class]);   // every endpoint in this group needs a login
```

### Step 5 — Find the right endpoint: `Router::dispatch()`
`classes/core/Router.php` compares `PATCH /v1/me/business` with each registered route:
1. does the path match? → yes
2. is it the right method (PATCH)? → yes
3. any `{id}`-style values in the path? → none here
4. run the **middleware** of that route → `AuthMiddleware`
5. run the endpoint's function

No match → `404 ROUTE_NOT_FOUND`. Path exists but with another method → `405`.

### Step 6 — Who is this? `AuthMiddleware`
`classes/core/AuthMiddleware.php` runs **before** the endpoint:
- the app sent `Authorization: Bearer 7f3a…` → `AuthToken::findUserIdByToken()` hashes the token and looks it up in `auth_tokens` (not expired, not revoked)
- (the website instead sends its session cookie, plus a CSRF token for changes)
- no valid login → it **throws** `ApiException::unauthenticated()` → the endpoint never runs → the app gets `401`
- valid → it stores `['user_id' => 7]` in the request: `$request->user()`

### Step 7 — The endpoint: a short block in `api/endpoints/profile.php`
```php
$router->patch('/business', function (Request $request) {
    $user_model = new User(Database::instance());
    return Response::success($user_model->updateBusiness($request->user()['user_id'], $request->all()));
});
```
It does three things only: take the logged-in user id, pass the body to the class, wrap the result in a `Response`. No SQL, no rules — those live in the class.

### Step 8 — The work: the class `User::updateBusiness()`
This is where the business rules are (like SCMRS's `Complaint::createComplaint()`):

1. **Validate** — `Validator::validate($input, self::BUSINESS_RULES)` checks every field and returns clean data (`"16"` becomes the number `16`, spaces are trimmed). Anything wrong → a `422` listing every bad field.
2. **Check business rules** — `checkLocation()`: region 16 must exist; if a district is given, it must belong to region 16.
3. **Save** — `saveBusiness()` runs one SQL statement through `Database`, with named placeholders:
   ```sql
   INSERT INTO business_profiles (user_id, business_name, region_id, district_id)
   VALUES (:user_id, :business_name, :region_id, :district_id)
   ON DUPLICATE KEY UPDATE ...
   ```
   The values travel separately from the SQL (prepared statement), so user input can never change the query.
4. **Return** the updated profile (`getProfile()`), read fresh from the database.

Because the rules are in the class, the **admin panel and the website use exactly the same checks** by calling the same method.

### Step 9 — The answer: `Response` → JSON
`Response::success($profile)` becomes:
```json
{"success": true, "data": {"user_id": 7, "user_full_name": "Joyce Joseph",
  "business": {"business_name": "Asha Cosmetics", "region_id": 16, "region_name": "Mwanza", ...},
  "is_profile_complete": true}}
```
`api/index.php` sends it with status `200` and the header `Content-Type: application/json`.

### When something goes wrong
Anywhere in steps 3–8, code can `throw` an `ApiException` (e.g. `ApiException::validation([...])`). It jumps straight back to `api/index.php`, which turns it into the error JSON with the right status code. Unexpected errors (a bug, the database down) are written to `storage/logs/` with the request id, and the app gets a safe `500` message — never PHP details.

---

## 3. Map of the files

```
api/.htaccess              every /api/... request → api/index.php
api/index.php              entrance: setup, load endpoints, run router, always answer JSON
api/endpoints/*.php        the endpoint list, one file per area (auth, profile, locations …)
classes/*.php              the work: one class per area (User, Otp, AuthToken, Region …)
classes/core/*.php         helpers used everywhere:
    Env            reads .env                 Request     the incoming request
    Database       safe SQL + transactions    Response    the JSON answer
    Router         URL → endpoint             Validator   checks and cleans input
    ApiException   errors → JSON              AuthMiddleware  login check before endpoints
    RateLimiter    stops too many attempts    Session / Csrf   website + admin logins
classes/sms/*.php          sending SMS (log file now; a real provider later)
database/migrations/*.sql  the tables
```

## 4. Login in one picture (phone + OTP)

```
App                                   Backend
 │ POST /auth/otp/request {phone} ──▶ CustomerAuth::requestLoginCode
 │                                      Validator (phone) → RateLimiter → Otp::createLoginCode
 │                                      (random 6 digits, stored only as a keyed hash) → SmsSender
 │ ◀── {otp_expires_in_seconds, (debug_otp_code in beta mode)}
 │ POST /auth/otp/verify {phone, code, client:"app"} ──▶ CustomerAuth::verifyLoginCode
 │                                      Otp::verifyLoginCode (expiry, 5 tries, single use)
 │                                      User::findOrCreateUserIdByPhone (first time = registration)
 │                                      AuthToken::createToken (64 random chars, stored hashed)
 │ ◀── {user, auth_token}
 │ app saves the token; every later request sends "Authorization: Bearer <token>"
```

## 5. Try it yourself (Postman)
1. `POST /api/v1/auth/otp/request` with `{"user_phone": "0712345678"}` → copy `debug_otp_code`
2. `POST /api/v1/auth/otp/verify` with `{"user_phone": "0712345678", "otp_code": "…", "client": "app"}` → copy `auth_token`
3. `PATCH /api/v1/me/business` with Bearer token and `{"business_name": "My Shop", "region_id": 2}`
4. Send step 3 again with `"region_id": 99999` → see the `422` error and its `fields`
5. Send it without the token → see the `401`
