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

## How It Works

1. Customer enters their bKash/Nagad/Rocket sender number
2. System polls `sms_data` table every 3 seconds (sender_key + amount + status)
3. When SMS arrives and matches → atomic claim → transaction completed
4. If no match after 30s → TRX ID fallback link appears
5. If no match after 8 minutes → transaction expired, redirect to merchant

## Installation

### 1. Database Migration

Run the SQL migration to add the verification method column:

```sql
-- sql/add_verification_method.sql
ALTER TABLE `pp_brand`
ADD COLUMN `verification_method` ENUM('trxid','phone') NOT NULL DEFAULT 'trxid';
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
                           ↓
                    Phone match? → Atomic claim → Completed → Redirect
                           ↓ (30s)
                    TRX ID fallback link appears
                           ↓ (8min)
                    Transaction expired → Redirect
```

## Requirements

- PipraPay payment gateway
- PHP 7.4+
- MySQL/MariaDB
- SMS reader device (Android app) capturing incoming payment SMS

## License

MIT
