# PipraPay — Phone Number Verification

A phone number-based payment verification system for [PipraPay](https://piprapay.com) payment gateway. Customers enter their sender phone number and the system automatically detects and verifies the payment via SMS polling.

## Features

- **Two-step wizard** — Phone input → Auto-polling → Payment verified
- **Auto-polling** — Server checks `sms_data` every 3 seconds for matching payments
- **Masked phone support** — Matches numbers like `0177***4073` (first-3 + last-4 digits)
- **TRX ID fallback** — Manual transaction ID entry after 30 seconds if auto-detect fails
- **8-minute countdown** — sessionStorage protection survives page refresh
- **Amount tolerance** — Uses brand's payment tolerance setting for partial matches
- **Back button intercept** — Phone back button returns to step 1 instead of exiting
- **Bangla QR display order** — Bangla QR gateway shown at top in MFS tab
- **Instant redirect** — `delay=0` skips status page, redirects to merchant immediately
- **Session timeout** — Status set to `expired` (not `canceled`) on countdown expiry
- **CSRF protection** — Token validation on all AJAX endpoints (poll/verify/cancel)
- **Session tracking** — Validates user session belongs to the transaction
- **24h time window** — SMS search limited to last 24 hours (hardcoded)
- **Webhook retry** — Exponential backoff (3 attempts: 0s, 2s, 4s) on failure

## How It Works

1. Customer enters their bKash/Nagad/Rocket sender number
2. System polls `sms_data` table every 3 seconds (sender_key + amount + status + 24h window)
3. When SMS arrives and matches → atomic claim → transaction completed
4. If no match after 30s → TRX ID fallback link appears
5. If no match after 8 minutes → transaction expired, redirect to merchant

## Security

- **CSRF tokens** — Every AJAX request includes `csrf_token` in FormData
- **Session validation** — Poll handler checks `$_SESSION['pp_phone_txn']` belongs to user
- **Atomic claims** — `UPDATE ... WHERE status = 'approved'` + `rowCount()` prevents double-spend
- **Server-side timeout** — 8-minute check enforced in poll + verify handlers
- **Gateway ownership** — Validates `gateway_id` matches transaction
- **Input sanitization** — `htmlspecialchars()` on all HTML output, parameterized queries

## Installation

### 1. Database Migration

Run the SQL migration to add the verification method column:

```sql
-- sql/add_verification_method.sql
ALTER TABLE `pp_brand`
ADD COLUMN `verification_method` ENUM('trxid','phone') NOT NULL DEFAULT 'trxid';

-- Performance index (recommended if sms_data > 100k rows)
CREATE INDEX IF NOT EXISTS idx_sms_data_verify 
ON pp_sms_data (sender_key, amount, status, created_date, id);
```

### 2. Files

Copy these files to your PipraPay installation:

```
pp-content/pp-include/phone-verify.php          # Core logic
sql/add_verification_method.sql                  # DB migration
```

### 3. Configuration

Go to **Brand Settings → General Settings** and select the verification method:

- **Transaction ID** — Traditional TRX ID entry (default)
- **Phone Number** — New phone-based auto-verification

## Architecture

```
phone-verify.php
├── pp_phone_normalize()        # +880/880/01X → 01XXXXXXXXX
├── pp_phone_match()            # Masked number support (first-3 + last-4)
├── pp_slug_to_sender_key()     # bkash-personal → bkash
├── pp_phone_calculate_payable() # Amount calculation (DRY)
├── pp_phone_send_webhook()     # Webhook dispatch (DRY + retry)
├── pp_phone_csrf_token()       # Generate CSRF token
├── pp_phone_csrf_validate()    # Validate CSRF token
├── pp_phone_csrf_rotate()      # Rotate after use
├── pp_phone_session_track()    # Track session → transaction
├── pp_phone_session_validate() # Validate session matches
├── pp_phone_session_clear()    # Clear session
├── pp_phone_handle_poll()      # Auto-polling (every 3s)
├── pp_phone_handle_verify()    # Manual TRX ID verify
├── pp_phone_handle_cancel()    # Countdown expiry (→ expired)
└── pp_phone_render_form()      # Phone form + JS rendering
```

## Flow

```
Phone Input → Validate → Step 2 (Waiting)
                           ↓
                     Poll sms_data (3s interval)
                     WHERE sender_key = ? AND amount = ? AND status = 'approved'
                       AND created_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                           ↓
                     CSRF + Session check passed?
                           ↓
                     Phone match? → Atomic claim → Completed → Redirect
                           ↓ (30s)
                     TRX ID fallback link appears
                           ↓ (8min)
                     Transaction expired → Redirect
```

## Webhook Retry

```
Attempt 1: Immediate
   ↓ (fail)
Attempt 2: Wait 2s
   ↓ (fail)
Attempt 3: Wait 4s
   ↓ (fail)
Log to webhook_log table
```

## Requirements

- PipraPay payment gateway
- PHP 7.4+
- MySQL/MariaDB
- SMS reader device (Android app) capturing incoming payment SMS

## Performance

Add this index to `sms_data` table for optimal poll performance (included in `sql/add_verification_method.sql`):

```sql
CREATE INDEX IF NOT EXISTS idx_sms_data_verify 
ON pp_sms_data (sender_key, amount, status, created_date, id);
```

Without this index, each 3-second poll does a full table scan on `sms_data` — acceptable for small volumes, but problematic at scale.

## License

MIT
