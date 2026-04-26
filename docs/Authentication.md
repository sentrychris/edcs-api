# EDCS Authentication

EDCS uses three cooperating auth systems. Understanding how they relate to each other is important before touching any auth-related code.

---

## Overview

| System | Purpose | Lifetime |
|--------|---------|----------|
| **Frontier OAuth2** (PKCE) | Proves the user is a legitimate Frontier account | Access token: 4 hours; Refresh token: 25 days |
| **Laravel Sanctum** | Proves a request to the EDCS API comes from an authenticated session | Never expires (controlled by NextAuth session) |
| **NextAuth** | Manages the frontend session and stores the Sanctum token | 30 days |

They are independent layers. Sanctum controls access to *our* API. Frontier tokens control our app's access to *Frontier's* CAPI. The NextAuth session is purely a frontend concern that stores the Sanctum token so it can be sent with each backend request.

---

## Login Flow

### 1 — Frontend initiates OAuth

The user clicks "Login with Frontier" in the sidebar (`sidebar-user.tsx`).

```
GET /api/auth/frontier/login
```

`FrontierAuthController::login()` calls `FrontierAuthService::getAuthorizationServerInformation()`, which:
- Generates a random **code verifier** (32 random bytes, base64url-encoded)
- Derives a **code challenge** from it (SHA-256 hash of the verifier, base64url-encoded, trailing `=` stripped)
- Generates a random **state** string (32 random bytes, base64url-encoded, no trailing `=`)
- Stores `{ state => code_verifier }` in the Laravel cache with a 60-second TTL
- Returns the full Frontier authorization URL

The frontend receives the `authorization_url` and redirects the browser to it.

### 2 — User authenticates with Frontier

The user logs in on `auth.frontierstore.net` and approves the application. Frontier redirects back to:

```
GET /api/auth/frontier/callback?code=CODE&state=STATE
```

### 3 — Backend exchanges code for tokens

`FrontierAuthController::callback()` runs:

1. **`FrontierAuthService::authorize()`** — retrieves the stored code verifier from cache using the `state` parameter, then POSTs to `https://auth.frontierstore.net/token`:
   ```
   grant_type=authorization_code
   client_id=CLIENT_ID
   code_verifier=CODE_VERIFIER
   code=CODE
   redirect_uri=REDIRECT_URI
   ```
   Frontier returns:
   ```json
   { "access_token": "...", "refresh_token": "...", "token_type": "Bearer", "expires_in": 14400 }
   ```

2. **`FrontierAuthService::decode()`** — calls `GET https://auth.frontierstore.net/decode` with the access token as a Bearer header to decrypt the JWT and retrieve the user's Frontier profile (customer ID, etc.).

3. **`FrontierAuthService::confirmUser()`** — upserts the local `User` and `FrontierUser` records:
   - `User::firstOrCreate` on `{email}` (email is `{customer_id}@versyx.net`)
   - `$user->frontierUser()->updateOrCreate` on `{frontier_id}` — stores `access_token`, `refresh_token`, `token_expires_at`
   - Caches the access token in Redis: `user_{id}_frontier_token` with a TTL 5 minutes shorter than `expires_in` (so a Redis miss can happen while the DB token is still valid)

4. **`FrontierCApiService::confirmCommander()`** — calls CAPI `/profile` immediately to create or update the local `Commander` record and resolve the commander's last system.

5. **Sanctum token** — any existing Sanctum tokens are revoked (`$user->tokens()->delete()`), then a new one is issued (`$user->createToken('frontier')`). The token never expires (`sanctum.expiration = null`); session lifetime is controlled by NextAuth.

6. **Redirect** — the backend redirects to `{FRONTEND_URL}/api/auth/callback` and sets an HttpOnly cookie:
   ```
   cmdr_token={sanctum_plain_text_token}; path=/; HttpOnly; [Secure]
   ```
   The cookie is scoped to 60 minutes (it only needs to survive the next step).

### 4 — Frontend completes the NextAuth session

The callback page (`app/api/auth/callback`) renders `AuthCallback` (a client component), which:

1. POSTs to `/api/auth/frontier/me` with `credentials: "include"` — the `cmdr_token` HttpOnly cookie is sent automatically.
2. `FrontierAuthenticated` middleware reads the cookie, finds the Sanctum token, and resolves the user. Returns `{ user, token }`.
3. The frontend calls `signIn("credentials", { user, token })` — NextAuth stores both in a 30-day encrypted JWT cookie. The `accessToken` field in the JWT is the Sanctum plain-text token.
4. The user is redirected to `/`.

---

## Request Authentication

Once logged in, every EDCS API call from the frontend sends the Sanctum token as a Bearer header:

```
Authorization: Bearer {sanctum_token}
```

This is read from `session.user.accessToken` (the NextAuth session) on the server-side Next.js components.

Routes protected by `auth:sanctum` middleware verify the token against `personal_access_tokens`. If valid, `$request->user()` resolves the local `User` model.

---

## Frontier Token Refresh

The Frontier access token expires 4 hours after issue. Refreshing it happens automatically inside `FrontierCApiService::getFrontierToken()`, which is called by every CAPI method before making a request.

### Resolution order

```
1. Redis key `user_{id}_frontier_token` exists → return it (fast path)

2. Redis miss → check `frontier_users.access_token` + `token_expires_at`:
     - token not expired → re-cache in Redis, return it

3. Token expired (or no token) → call FrontierAuthService::refreshToken():
     POST https://auth.frontierstore.net/token
       grant_type=refresh_token
       client_id=CLIENT_ID
       refresh_token={stored_refresh_token}
     
     On success:
       - Update frontier_users: new access_token, refresh_token, token_expires_at
       - Update Redis cache
       - Return new access token

     On 401 from Frontier (refresh token expired or revoked):
       - Throw FrontierReauthorizationRequiredException
```

### Redis TTL strategy

The Redis key TTL is set to `expires_in - 300` seconds (5 minutes shorter than the real token expiry). This means a Redis miss occurs while the access token is still technically valid, allowing the DB path to re-cache it without immediately needing a refresh. This prevents a situation where Redis expires exactly when Frontier's token also expires, reducing the refresh window to a few seconds.

### Refresh token lifetime

Frontier refresh tokens are valid for **25 days** from the initial authorization. After 25 days, the user must go through the full OAuth flow again. The NextAuth session lasts 30 days, so there is a 5-day window where the user appears logged in but CAPI calls will fail with `FrontierReauthorizationRequiredException`.

---

## Reauthorization

When `FrontierReauthorizationRequiredException` is thrown (refresh token expired or Frontier access revoked), it propagates through the CAPI service and is rendered by `app/Exceptions/Handler.php` as:

```json
HTTP 401
{ "message": "Frontier reauthorization required.", "requires_reauth": true }
```

The frontend detects this in `app/commander/page.tsx`: if `ApiError.status === 401`, `requiresReauth` is set and `<CommanderReauth />` is rendered instead of the profile. The component presents a "Re-authorize with Frontier" button that restarts the login flow from step 1.

---

## Logout

The logout button in `SidebarUser` executes in order:

1. **Backend revocation** — `POST /api/auth/logout` with `Authorization: Bearer {sanctum_token}`. The backend calls `$user->tokens()->delete()`, removing all Sanctum tokens for the user from `personal_access_tokens`.
2. **Cookie removal** — the `cmdr_token` HttpOnly cookie is cleared in the browser.
3. **NextAuth session** — `signOut()` clears the NextAuth JWT cookie.

Step 1 is best-effort: a network failure does not block steps 2 and 3, so the user is never left in a partially-logged-out state. The Sanctum token would then persist in the DB until the user's next login triggers `$user->tokens()->delete()` again.

---

## Token Storage

| Token | Where stored | Notes |
|-------|-------------|-------|
| Frontier access token | `frontier_users.access_token` (DB) + Redis `user_{id}_frontier_token` | Redis is the fast path; DB is the source of truth |
| Frontier refresh token | `frontier_users.refresh_token` (DB) | Never cached; only used when access token is expired |
| Frontier token expiry | `frontier_users.token_expires_at` (DB) | Used to decide whether to re-cache or refresh |
| Sanctum token (hashed) | `personal_access_tokens` (DB) | Plain text stored in NextAuth JWT and `cmdr_token` cookie |
| NextAuth session | Encrypted JWT cookie in browser | Contains Sanctum plain-text token + commander data |

---

## Key Files

### Backend (`edcs/`)

| File | Role |
|------|------|
| `app/Services/Frontier/FrontierAuthService.php` | OAuth2 PKCE helpers, token exchange, user upsert, token refresh |
| `app/Services/Frontier/FrontierCApiService.php` | CAPI requests, token resolution with Redis/DB/refresh fallback |
| `app/Http/Controllers/FrontierAuthController.php` | Login, callback, and `/me` endpoints |
| `app/Http/Middleware/FrontierAuthenticated.php` | Validates `cmdr_token` cookie for the `/me` endpoint |
| `app/Exceptions/FrontierReauthorizationRequiredException.php` | Thrown when the Frontier refresh token is expired or revoked |
| `app/Exceptions/Handler.php` | Renders `FrontierReauthorizationRequiredException` as HTTP 401 |
| `app/Models/FrontierUser.php` | Stores Frontier tokens; `isTokenExpired()` checks `token_expires_at` |
| `config/sanctum.php` | `expiration: null` — tokens never expire in the DB |

### Frontend (`edcs-app/`)

| File | Role |
|------|------|
| `core/auth.ts` | NextAuth config; JWT and session callbacks store `accessToken` |
| `core/api.ts` | `request()` helper; throws `ApiError` (with HTTP status) on non-2xx |
| `app/api/auth/callback/components/callback.tsx` | Reads `cmdr_token` cookie, calls `/me`, bootstraps NextAuth session |
| `components/sidebar/sidebar-user.tsx` | Login and logout UI; logout revokes backend token before `signOut()` |
| `app/commander/page.tsx` | Detects `ApiError(401)` from CAPI and renders reauth panel |
| `app/commander/components/commander-reauth.tsx` | Re-authorize button; restarts Frontier OAuth flow |

---

## Sequence Diagram

```
Browser          Next.js (edcs-app)        Laravel (edcs)         Frontier
  │                     │                       │                      │
  │  Click "Login"      │                       │                      │
  │────────────────────>│                       │                      │
  │                     │  GET /auth/frontier/login                    │
  │                     │──────────────────────>│                      │
  │                     │  { authorization_url } │                      │
  │                     │<──────────────────────│                      │
  │  redirect to Frontier auth URL              │                      │
  │────────────────────────────────────────────────────────────────────>
  │                     │                       │  User approves       │
  │  GET /auth/frontier/callback?code&state     │                      │
  │────────────────────────────────────────────>│                      │
  │                     │                       │  POST /token (PKCE)  │
  │                     │                       │─────────────────────>│
  │                     │                       │  { access_token,     │
  │                     │                       │    refresh_token }   │
  │                     │                       │<─────────────────────│
  │                     │                       │  GET /decode         │
  │                     │                       │─────────────────────>│
  │                     │                       │  { frontier profile }│
  │                     │                       │<─────────────────────│
  │                     │                       │  upsert User +       │
  │                     │                       │  FrontierUser + cache│
  │                     │                       │  GET /profile (CAPI) │
  │                     │                       │─────────────────────>│
  │                     │                       │  upsert Commander    │
  │                     │                       │  create Sanctum token│
  │  redirect + cmdr_token cookie               │                      │
  │<────────────────────────────────────────────│                      │
  │                     │                       │                      │
  │  GET /api/auth/callback (Next.js page)      │                      │
  │────────────────────>│                       │                      │
  │                     │  POST /auth/frontier/me (cookie)             │
  │                     │──────────────────────>│                      │
  │                     │  { user, token }      │                      │
  │                     │<──────────────────────│                      │
  │                     │  signIn("credentials")│                      │
  │                     │  → NextAuth JWT set   │                      │
  │  redirect /         │                       │                      │
  │<────────────────────│                       │                      │
  │                     │                       │                      │
  │  GET /commander     │                       │                      │
  │────────────────────>│                       │                      │
  │                     │  GET /frontier/capi/profile                  │
  │                     │    Authorization: Bearer {sanctum_token}     │
  │                     │──────────────────────>│                      │
  │                     │                       │  getFrontierToken()  │
  │                     │                       │  Redis → DB → refresh│
  │                     │                       │  GET /profile (CAPI) │
  │                     │                       │─────────────────────>│
  │                     │                       │  { commander data }  │
  │                     │                       │<─────────────────────│
  │                     │  { commander profile }│                      │
  │                     │<──────────────────────│                      │
  │  render /commander  │                       │                      │
  │<────────────────────│                       │                      │
```
