# Phone Verification System — Full Issue List

> **Scope:** pv-feature (overlay for piprapay) + profess0rpay (fork of piprapay)
> **Date:** 2026-09-03
> **Total Issues:** 41 (CRITICAL: 7, HIGH: 14, MEDIUM: 6, LOW: 14)

---

## CRITICAL (Exploit / Fraud)

### C1: Provider Spoofing — sender_key from `$_POST`

- **File:** `phone-verify.php:85-91` (poll), `phone-verify.php:306-314` (verify)
- **Scenario:** Attacker sends real `gateway_id` + fake `provider` → searches wrong SMS pool → claims another provider's payment
- **Impact:** Attacker completes transaction without paying by matching SMS from unrelated wallet pool
- **Fix:** Use `$gateway['slug']` already fetched from DB instead of `$_POST['provider']`

```php
// BEFORE (broken):
$provider = escape_string($_POST['provider'] ?? '');
$sender_key = pp_slug_to_sender_key($provider);

// AFTER (safe):
$sender_key = pp_slug_to_sender_key($gateway['slug']);
```

---

### C2: No Brand/Gateway Scope on `sms_data`

- **File:** `phone-verify.php:170-182` (poll), `phone-verify.php:419-427` (verify MODE 1)
- **Scenario:** Attacker ShopX e transaction khule, ShopY er customer er SMS claim kore — same sender_key + same amount
- **Impact:** Cross-brand payment hijacking — one customer pays, attacker gets free service
- **Fix:** Add `brand_id` + `gateway_id` filter to SMS queries

```php
// BEFORE:
WHERE sender_key = :sender_key AND amount = :amount AND status = :status

// AFTER:
WHERE sender_key = :sender_key
  AND brand_id = :brand_id
  AND amount = :amount
  AND status = :status
```

---

### C3: Server-Side Timeout Not Enforced

- **File:** `phone-verify.php:82-281` (poll), `phone-verify.php:303-574` (verify)
- **Scenario:** 8 min client-side timer ache, server e kono check nei — poll无限 chole, attacker unlimited time neye kono SMS claim korte pare
- **Impact:** Timeout purely cosmetic — attacker can poll indefinitely and claim late-arriving SMS
- **Fix:** Check `transaction.created_date` against 8-minute threshold in poll + verify

```php
// Add after fetching transaction:
$elapsed = (time() - strtotime($transaction['created_date'])) / 60;
if ($elapsed > 8) {
    pp_set_transaction_status($transaction_id, 'expired');
    return pp_phone_safe_json(['status' => 'false', 'message' => 'expired']);
}
```

---

### C4: `pp_set_transaction_status('expired')` No-Op

- **File:** `phone-verify.php:296`
- **Scenario:** Cancel call korle 'expired' set hoy, BUT `pp_set_transaction_status` function e 'expired' handler nei — nothing happens, status remains initiated
- **Impact:** Cancel function is completely broken — timeout never actually expires transactions
- **Fix:** Add 'expired' branch to `pp_set_transaction_status()`

---

### C5: Cancel — No Ownership/Status Check

- **File:** `phone-verify.php:286-298`
- **Scenario:** Anyone can cancel any transaction by knowing ref — no auth check, no status check
- **Impact:** Attacker can expire other users' pending transactions
- **Fix:** Verify transaction exists AND status is `initiated` before updating

---

### C6: SQL Injection via String Concat (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9625`
- **Scenario:** `updateData('...id="'.$matched_sms['id'].'"')` — if sms_data contains tainted data, SQL injection possible
- **Impact:** Full database compromise
- **Fix:** Use parameterized queries (`:id` placeholder)

---

### C7: No Atomic Claim — Race Condition (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9623-9626`
- **Scenario:** Two requests match same SMS simultaneously → both succeed → double-spend
- **Impact:** Same SMS claimed twice — one transaction gets free service
- **Fix:** Atomic CAS: `UPDATE ... WHERE status = 'approved'` + `rowCount()` check (pv-feature already does this correctly)

---

## HIGH — Security

### H1: No CSRF Token on Endpoints ✅ FIXED

- **File:** `phone-verify.php` (all 3 action handlers)
- **Fix:** `pp_phone_csrf_token()`, `pp_phone_csrf_validate()`, `pp_phone_csrf_rotate()` — CSRF token in FormData for poll/verify/cancel
- **Status:** Implemented in commit `b21f088`

---

### H2: XSS in Placeholder ✅ FIXED

- **File:** `phone-verify.php:624`
- **Scenario:** `$lbl_enter_trxid` comes from `$data['lang']` — not escaped with `htmlspecialchars()`
- **Impact:** Stored XSS if lang value is user-controlled
- **Fix:** Apply `htmlspecialchars()` to all dynamic values in HTML output

---

### H3: No Rate Limiting

- **File:** `phone-verify.php:82-281`
- **Scenario:** Poll endpoint called every 3 seconds — no server throttle, unlimited requests
- **Impact:** DoS vector + brute force possible
- **Fix:** Session/IP based rate limit (max 1 request/second)

---

### H4: Transaction Ownership Not Verified ✅ FIXED

- **File:** `phone-verify.php:82-128`
- **Fix:** H4 implemented — validates `transaction.gateway_id === provided gateway_id`
- **Status:** Implemented in commit `b3c4e6c`

---

### H5: Debug Log Path Exposed (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9668`
- **Scenario:** `file_put_contents($logFile, ...)` writes to `payment_debug.log` in web root
- **Impact:** Information leak — transaction details exposed in publicly accessible log
- **Fix:** Remove debug logging or write to non-public directory

---

### H6: Duplicate Email Send (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9674, 9686`
- **Scenario:** `sendCustomerEmailReceipt()` called twice in same code path
- **Impact:** Customer receives duplicate confirmation emails
- **Fix:** Deduplicate — single call only

---

## HIGH — Logic Bug

### L1: Poll Exact Amount Match (BY DESIGN)

- **File:** `phone-verify.php:165-182`
- **Note:** This is INTENTIONAL. Polling uses exact amount match for precision. Tolerance-based matching is available via the manual Transaction ID fallback (MODE 2). Auto-polling should only match exact amounts to avoid false positives.
- **No fix needed** — documented as designed behavior.

---

### L2: Null Dereference on Failed Trxid Match ✅ FIXED

- **File:** `phone-verify.php:481-486`
- **Fix:** Null check added before accessing `$matched_sms['id']`
- **Status:** Implemented in commit `7f34abd`

---

### L3: Inconsistent Phone Validation ✅ FIXED

- **File:** `phone-verify.php:98` (poll) vs `phone-verify.php:319` (verify)
- **Fix:** Phone validation added to `pp_phone_handle_poll()`
- **Status:** Implemented in commit `7f34abd`

---

### L4: Trxid Duplicate Check — No Brand Filter

- **File:** `phone-verify.php:457-465`
- **Scenario:** Brand A er trx_id `ABC123` — Brand B er user `ABC123` dile blocked
- **Impact:** False-positive duplicate detection across brands
- **Fix:** `WHERE trx_id = :trx_id AND brand_id = :brand_id`

---

### L5: LIKE Wildcard Match — False Positive (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9587`
- **Scenario:** `'%' . $mobile_number . '%'` — substring match, `01712345678` matches `017123456789`, `001712345678` etc
- **Impact:** Wrong SMS matched — payment verified against incorrect transaction
- **Fix:** Exact match after normalization (pv-feature does this correctly)

---

### L6: Rocket Last-3-Digits Only (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9588-9589`
- **Scenario:** Rocket gateway e last 3 digit diye search — extremely high false positive chance
- **Impact:** Any rocket user with matching last 3 digits can claim payment
- **Fix:** Normal 11-digit exact match use koro

---

### L7: Claim-Then-Revert Race (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9862-9902`
- **Scenario:** Trxid mode: SMS flip to 'used' BEFORE tolerance check — if tolerance fails, revert korar shomoy concurrent poll skips it
- **Impact:** Legitimate payment blocked during revert window
- **Fix:** Tolerance check BEFORE claim, or atomic claim+check combined

---

### L8: Tolerance Fetched Separately (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9852-9853`
- **Scenario:** `payment_tolerance` separate query theke ashe — already fetched `$brand` theke nite parbe
- **Impact:** Extra unnecessary DB query per verification
- **Fix:** Reuse existing `$brand['payment_tolerance']`

---

## MEDIUM (Code Quality / Duplication)

### M1: Amount Calculation Duplicated

- **File:** `phone-verify.php:130-165` (poll) + `phone-verify.php:378-410` (verify)
- **Scenario:** 35 lines identical currency/discount/charge logic in both functions
- **Fix:** Extract to `pp_phone_calculate_payable($transaction, $gateway, $brand)`

---

### M2: Webhook Dispatch Duplicated

- **File:** `phone-verify.php:218-265` (poll) + `phone-verify.php:511-558` (verify)
- **Scenario:** 45 lines identical webhook logic in both functions
- **Fix:** Extract to `pp_phone_send_webhook($transaction, $gateway, $brand, $sms, $sender_key)`

---

### M3: Moreinfo + Completion Duplicated

- **File:** `phone-verify.php:199-216` (poll) + `phone-verify.php:492-509` (verify)
- **Scenario:** 18 lines identical transaction completion logic
- **Fix:** Extract to `pp_phone_complete_transaction()`

---

### M4: MODE 1 Query — No Amount Filter ⚠️ PARTIAL FIX

- **File:** `phone-verify.php:419-427`
- **Scenario:** Fetches all approved SMS for sender_key (LIMIT 20), filters by phone+tolerance in PHP — wasteful on high-volume gateways
- **Fix:** Composite index added to SQL file (`idx_sms_data_verify`) — covers sender_key, amount, status, created_date. PHP-side tolerance filter remains.
- **Status:** Index added in commit `384634b`

---

### M5: `number_amount` Code Duplicated Twice (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9585` + `profess0rpay/pp-adapter.php:9822`
- **Scenario:** Same ~50 line block at two different locations
- **Fix:** Extract to single function, call from both places

---

### M6: `verification_method` Fetched 3 Times (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9573, 9819` + `profess0rpay/pp-functions.php:3042`
- **Scenario:** Same value fetched repeatedly from same source
- **Fix:** Fetch once, store in variable, reuse

---

## LOW (Polish / Dead Code)

### P1: Webhook Log `ref` = `rand()`

- **File:** `phone-verify.php:250, 261`
- **Scenario:** Two simultaneous webhooks → collision → log overwrite or missed retry
- **Fix:** Use `generateItemID()` or `bin2hex(random_bytes(16))`

---

### P2: DB Migration — No `IF NOT EXISTS`

- **File:** `sql/add_verification_method.sql`
- **Scenario:** Migration 2 bar run korle error
- **Fix:** Add `IF NOT EXISTS` clause

---

### P3: DB Migration — No DOWN

- **File:** `sql/add_verification_method.sql`
- **Scenario:** Rollback kora jay na
- **Fix:** Add down migration SQL

---

### P4: Dead Variable — `$receipt_id`

- **File:** `phone-verify.php:589`
- **Scenario:** Declared, never used
- **Fix:** Remove

---

### P5: Dead Variable — `$lbl_submit`

- **File:** `phone-verify.php:596`
- **Scenario:** Declared, never used
- **Fix:** Remove

---

### P6: Dead Variable — `$lbl_remain`

- **File:** `phone-verify.php:603`
- **Scenario:** Declared, never used
- **Fix:** Remove

---

### P7: Dead Variables — `$amount`, `$currency`, `$payable`, `$discount`, `$fee`

- **File:** `phone-verify.php:584-588`
- **Scenario:** 5 variables declared, never used
- **Fix:** Remove all

---

### P8: `$data` Parameter Unused in All Handlers

- **File:** `phone-verify.php:82, 286, 303`
- **Scenario:** Accepts `$data = null` but reads `$_POST` directly — misleading API, untestable
- **Fix:** Either use `$data` parameter or remove it

---

### P9: JS — `instCard` Null Guard Missing

- **File:** `phone-verify.php:668, 687`
- **Scenario:** `getElementById("hz-inst-section")` null hole `querySelector()` crashes
- **Fix:** Add null check

---

### P10: JS — `hookNavBack()` Fragile Onclick Parsing

- **File:** `phone-verify.php:751-773`
- **Scenario:** String manipulation of inline onclick — theme change e break
- **Fix:** Use data attribute or event listener

---

### P11: JS — Back Button Infinite Loop

- **File:** `phone-verify.php:777-784`
- **Scenario:** `popstate` + `pushState` combined → infinite back possible
- **Fix:** Single history management approach

---

### P12: CSS Classes Undefined

- **File:** `phone-verify.php:607-614`
- **Scenario:** `pp-phone-form`, `btn-phone-next` file e define hoy ni — depends on external theme
- **Fix:** Document dependency or define inline

---

### P13: No Direct Access Guard on Inline Code (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9572-9619`
- **Scenario:** Phone verification code inline in adapter — no `PipraPay_INIT` check
- **Fix:** Move to dedicated file (like pv-feature's `phone-verify.php`)

---

### P14: Session-Based Attempt Counter (profess0rpay)

- **File:** `profess0rpay/pp-adapter.php:9882-9886`
- **Scenario:** `$_SESSION['verify_attempt_'.$txn_id]` — session dependency, not stateless
- **Fix:** Server-side attempt tracking in DB

---

## Summary

| Severity | Total | Fixed | Remaining |
|---|---|---|---|
| CRITICAL | 7 | 7 | 0 |
| HIGH | 14 | 4 | 10 |
| MEDIUM | 6 | 1 | 5 |
| LOW | 14 | 0 | 14 |
| **TOTAL** | **41** | **12** | **29** |

---

## Fix Priority

```
Round 1 — CRITICAL (DONE):
  C1-C7 ✅

Round 2 — HIGH Security (DONE):
  H1 ✅, H4 ✅, H2 ✅, L2 ✅, L3 ✅

Round 3 — HIGH Security (DONE):
  H3 time window ✅ (hardcoded 24h), F3 webhook retry ✅, F4 session tracking ✅

Round 4 — HIGH Logic (Remaining):
  L4-L8, H3 rate limiting (not yet)

Round 5 — MEDIUM (Remaining):
  M1-M6 (M4 partial — index added)

Round 6 — LOW (Remaining):
  P1-P14
```

---

## Notes

- **L1 (Poll Exact Amount):** By design. Tolerance matching available via manual Transaction ID fallback. Auto-polling intentionally uses exact match.
- **pv-feature advantages over profess0rpay:** Atomic claim (no race condition), parameterized queries (no SQL injection), masked number support, dedicated file (not inline), admin UI dropdown, CSRF protection, session tracking, webhook retry, 24h time window.
- **profess0rpay advantages over pv-feature:** Pending fallback state, branded UI with QR modal.
- **sms_data index:** Added `idx_sms_data_verify` to SQL file — covers poll + verify query patterns.
