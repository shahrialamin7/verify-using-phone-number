<?php
/**
 * Phone Number Verification Controller for PipraPay
 * 
 * Standalone controller for phone number based payment verification.
 * All logic isolated in this file — zero modification to existing files.
 * 
 * Flow:
 * 1. User enters phone number at checkout page
 * 2. System starts polling for matching SMS by phone + amount
 * 3. When SMS arrives, system matches and verifies automatically
 * 4. Fallback: manual transaction ID entry if no match after timeout
 * 
 * Only applies to automation gateways under brands that selected this method.
 */

// Direct access guard
if (!defined('PipraPay_INIT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

/**
 * Phone number normalization
 * Converts +880, 880, 01XXXXXXXXX all to 01XXXXXXXXX
 */
function pp_phone_normalize($phone) {
    $phone = preg_replace('/[\s\-\+]/', '', trim($phone));
    if (strpos($phone, '880') === 0) {
        $phone = '0' . substr($phone, 3);
    }
    return $phone;
}

/**
 * Phone number match — supports masked SMS numbers like 0177***4073
 * Matches by first 3 + last 4 digits
 */
function pp_phone_match($user_phone, $sms_number) {
    $clean_user = preg_replace('/\D/', '', $user_phone);
    
    // Handle masked numbers: 0177***4073, 017****678, etc
    if (strpos($sms_number, '*') !== false) {
        $parts = explode('*', $sms_number);
        $first = preg_replace('/\D/', '', $parts[0]);
        $last  = preg_replace('/\D/', '', end($parts));
        
        if (strlen($first) < 3 || strlen($last) < 4) return false;
        
        $user_starts = substr($clean_user, 0, strlen($first));
        $user_ends   = substr($clean_user, -strlen($last));
        
        return ($user_starts === $first && $user_ends === $last);
    }
    
    // Full number match
    $clean_sms = preg_replace('/\D/', '', $sms_number);
    if (strlen($clean_user) < 11 || strlen($clean_sms) < 11) {
        return false;
    }
    return ($clean_user === $clean_sms);
}

/**
 * Safe JSON output wrapper
 */
function pp_phone_safe_json($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

/**
 * Convert gateway slug to sms_data sender_key
 * bkash-personal → bkash, nagad-merchant → nagad, etc.
 */
function pp_slug_to_sender_key($slug) {
    return preg_replace('/-(personal|merchant|agent)$/i', '', $slug);
}

/**
 * Set transaction status to expired via direct DB update
 * Used by server-side timeout enforcement
 */
function pp_phone_set_expired($transaction_id) {
    global $db_prefix;
    $pdo = connectDatabase();
    $stmt = $pdo->prepare('UPDATE '.$db_prefix.'transaction SET status = :expired, updated_date = :now WHERE ref = :ref AND status = :initiated');
    $stmt->execute([
        ':expired'  => 'expired',
        ':now'      => getCurrentDatetime('Y-m-d H:i:s'),
        ':ref'      => $transaction_id,
        ':initiated' => 'initiated',
    ]);
}

/**
 * H1: Generate CSRF token for phone verification
 */
function pp_phone_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['pp_phone_csrf'])) {
        $_SESSION['pp_phone_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pp_phone_csrf'];
}

/**
 * H1: Validate CSRF token
 */
function pp_phone_csrf_validate($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['pp_phone_csrf']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['pp_phone_csrf'], $token);
}

/**
 * H1: Rotate CSRF token (call after successful action)
 */
function pp_phone_csrf_rotate() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['pp_phone_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['pp_phone_csrf'];
}

/**
 * F4: Store transaction in session for ownership tracking
 */
function pp_phone_session_track($transaction_id) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['pp_phone_txn'] = $transaction_id;
}

/**
 * F4: Validate transaction session ownership
 */
function pp_phone_session_validate($transaction_id) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['pp_phone_txn']) && $_SESSION['pp_phone_txn'] === $transaction_id;
}

/**
 * F4: Clear transaction session
 */
function pp_phone_session_clear() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['pp_phone_txn']);
}

/**
 * Calculate payable amount from transaction + gateway config
 * Returns formatted amount string
 */
function pp_phone_calculate_payable($transaction, $gateway, $brand) {
    global $db_prefix;
    $currencyRates = [];
    $currencyRes = json_decode(getData($db_prefix.'currency', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $brand['brand_id']]), true);
    if (!empty($currencyRes['response'])) {
        foreach ($currencyRes['response'] as $c) {
            $currencyRates[$c['code']] = $c['rate'];
        }
    }

    $txnAmount    = money_sanitize($transaction['amount']);
    $txnCurrency  = $transaction['currency'];
    $gwCurrency   = $gateway['currency'];

    if ($txnCurrency === $gwCurrency) {
        $convertedAmount = $txnAmount;
    } else {
        $convertedAmount = isset($currencyRates[$gwCurrency])
            ? money_div($txnAmount, $currencyRates[$gwCurrency])
            : "0";
    }

    $fixed_discount     = money_sanitize($gateway['fixed_discount']);
    $percentage_discount = money_sanitize($gateway['percentage_discount']);
    $fixed_charge       = money_sanitize($gateway['fixed_charge']);
    $percentage_charge  = money_sanitize($gateway['percentage_charge']);

    $pctDiscAmt = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
    $totalDiscount = money_add($fixed_discount, $pctDiscAmt, 8);

    $pctChgAmt = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
    $totalProcessingFee = money_add($fixed_charge, $pctChgAmt, 8);

    $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

    return number_format((float)$convertedAmount, 2, '.', '');
}

/**
 * Send webhook for completed transaction
 */
function pp_phone_send_webhook($transaction, $gateway, $brand, $sms, $sender_key) {
    global $db_prefix;

    if (empty($transaction['webhook_url']) || $transaction['webhook_url'] === '--') {
        return;
    }

    $customer_info = json_decode($transaction['customer_info'], true) ?: [];
    $metadata = json_decode($transaction['metadata'], true) ?: [];

    $ipnData = [
        'pp_id'           => $transaction['ref'],
        'full_name'       => $customer_info['name'] ?? 'N/A',
        'email_address'   => $customer_info['email'] ?? 'N/A',
        'mobile_number'   => $customer_info['mobile'] ?? 'N/A',
        'gateway'         => $gateway['name'] ?? 'Phone Verify',
        'amount'          => money_round($transaction['amount']),
        'fee'             => money_round($transaction['processing_fee']),
        'discount_amount' => money_round($transaction['discount_amount']),
        'total'           => money_sub(money_add($transaction['amount'], $transaction['processing_fee']), $transaction['discount_amount']),
        'local_net_amount'=> money_round($transaction['local_net_amount']),
        'currency'        => $transaction['currency'],
        'local_currency'  => $transaction['local_currency'],
        'metadata'        => $metadata,
        'sender'          => $sms['number'],
        'sender_key'      => $sender_key,
        'sender_type'     => $sms['type'],
        'transaction_id'  => $sms['trx_id'],
        'status'          => 'completed',
        'date'            => convertUTCtoUserTZ(
            $transaction['created_date'],
            ($brand['timezone'] === '--' || $brand['timezone'] === '') ? 'Asia/Dhaka' : $brand['timezone'],
            "M d, Y h:i A"
        ),
    ];

    // F3: Webhook retry with exponential backoff (3 attempts: 0s, 2s, 4s)
    $max_attempts = 3;
    $success = false;

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        if ($attempt > 0) {
            sleep(pow(2, $attempt)); // 2s, 4s
        }

        $job_id = bin2hex(random_bytes(16));
        $jobs = [[
            'id'      => $job_id,
            'url'     => $transaction['webhook_url'],
            'payload' => $ipnData,
        ]];

        $results = sendIPNMulti($jobs);
        $code = $results[$job_id] ?? 0;

        if ($code === 200) {
            $success = true;
            break;
        }
    }

    if (!$success) {
        $columns = ['ref', 'brand_id', 'payload', 'url', 'created_date', 'updated_date'];
        $values = [bin2hex(random_bytes(16)), $brand['brand_id'], json_encode($ipnData, JSON_UNESCAPED_UNICODE), $transaction['webhook_url'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];
        insertData($db_prefix.'webhook_log', $columns, $values);
    }
}

/**
 * Handle polling — check if SMS matches phone + amount
 */
function pp_phone_handle_poll($data = null) {
    global $db_prefix;

    // H1: CSRF validation
    $csrf_token = escape_string($_POST['csrf_token'] ?? '');
    if (!pp_phone_csrf_validate($csrf_token)) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Invalid request.']);
    }

    $gateway_id     = escape_string($_POST['gateway_id'] ?? '');
    $transaction_id = escape_string($_POST['transaction_id'] ?? '');
    $phone          = escape_string($_POST['mobile_number'] ?? '');

    if (empty($gateway_id) || empty($transaction_id) || empty($phone)) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Missing parameters.']);
    }

    // F4: Validate session ownership
    if (!pp_phone_session_validate($transaction_id)) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Session mismatch.']);
    }

    // Normalize phone
    $phone = pp_phone_normalize($phone);

    // L3: Validate phone format (same as verify handler)
    if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Invalid number format.']);
    }

    // Fetch transaction
    $params = [':ref' => $transaction_id, ':status' => 'initiated'];
    $response_txn = json_decode(getData($db_prefix.'transaction', 'WHERE ref = :ref AND status = :status', '* FROM', $params), true);

    if ($response_txn['status'] !== true) {
        return pp_phone_safe_json(['status' => 'true', 'message' => 'already_verified']);
    }

    $transaction = $response_txn['response'][0];

    // H4: Verify gateway belongs to this transaction
    if (!empty($transaction['gateway_id']) && $transaction['gateway_id'] != $gateway_id) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Gateway mismatch.']);
    }

    // Fetch brand
    $params = [':brand_id' => $transaction['brand_id']];
    $response_brand = json_decode(getData($db_prefix.'brands', 'WHERE brand_id = :brand_id', '* FROM', $params), true);

    if ($response_brand['status'] !== true) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Brand not found.']);
    }

    $brand = $response_brand['response'][0];

    // Fetch gateway config
    $params = [':gateway_id' => $gateway_id, ':brand_id' => $transaction['brand_id']];
    $response_gw = json_decode(getData($db_prefix.'gateways', 'WHERE gateway_id = :gateway_id AND brand_id = :brand_id AND status = "active"', '* FROM', $params), true);

    if ($response_gw['status'] !== true) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Gateway not found.']);
    }

    $gateway = $response_gw['response'][0];

    // C1: Derive sender_key from gateway slug (server-side, not client)
    $sender_key = pp_slug_to_sender_key($gateway['slug']);

    // C3: Server-side timeout check — 8 minutes max
    $elapsed = (time() - strtotime($transaction['created_date'])) / 60;
    if ($elapsed > 8) {
        pp_phone_set_expired($transaction_id);
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Session Expired',
            'message' => 'Payment verification window has expired.',
        ]);
    }

    // Calculate payable amount
    $payableAmount = pp_phone_calculate_payable($transaction, $gateway, $brand);

    $pdo = connectDatabase();

    // H3: sms_data_validity — fetch from brand settings, default 30 min
    $validity_minutes = intval($brand['sms_data_validity'] ?? 0);
    if ($validity_minutes < 1) $validity_minutes = 30; // fallback default

    // Search sms_data: provider + phone + exact amount + approved
    // H3: Add time window filter
    $findSql = 'SELECT id, trx_id, number, amount, sender, type 
                FROM '.$db_prefix.'sms_data 
                WHERE sender_key = :sender_key 
                  AND amount = :amount 
                  AND status = :status 
                  AND created_date > DATE_SUB(NOW(), INTERVAL :validity MINUTE)
                ORDER BY id DESC 
                LIMIT 10';
    $findStmt = $pdo->prepare($findSql);
    $findStmt->execute([
        ':sender_key' => $sender_key,
        ':amount'    => $payableAmount,
        ':status'    => 'approved',
        ':validity'  => $validity_minutes
    ]);
    $candidates = $findStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as $sms) {
        // Full phone number match
        $phone_to_match = (!empty($sms['sender']) && $sms['sender'] !== '--') ? $sms['sender'] : $sms['number'];
        
        if (!pp_phone_match($phone, $phone_to_match)) {
            continue;
        }

        // Atomic claim
        $claimStmt = $pdo->prepare('UPDATE '.$db_prefix.'sms_data SET status = :used WHERE id = :sms_id AND status = :approved');
        $claimStmt->execute([':used' => 'used', ':sms_id' => $sms['id'], ':approved' => 'approved']);

        if ($claimStmt->rowCount() > 0) {
            // Complete transaction
            $moreinfo = [
                ['label' => 'Provider',      'value' => ucfirst($sender_key)],
                ['label' => 'Mobile Number',  'value' => $sms['number']],
                ['label' => 'Match Method',   'value' => 'phone-number'],
            ];

            pp_set_transaction_status(
                $transaction['ref'],
                'completed',
                $gateway_id,
                $sms['trx_id'],
                $moreinfo,
                [
                    'sender'      => $sms['number'],
                    'sender_key'  => $sender_key,
                    'sender_type' => $sms['type'],
                ]
            );

            // Webhook
            pp_phone_send_webhook($transaction, $gateway, $brand, $sms, $sender_key);

            return pp_phone_safe_json([
                'status'   => 'true',
                'title'    => 'Payment Verified',
                'message'  => 'Your payment has been verified successfully.',
                'trx_id'   => $sms['trx_id'],
                'redirect'  => pp_checkout_address($transaction['ref']),
            ]);
        }
    }

    return pp_phone_safe_json([
        'status'  => 'false',
        'message' => 'waiting',
    ]);
}

/**
 * Handle cancel when countdown expires
 */
function pp_phone_handle_cancel($data = null) {
    global $db_prefix;

    $transaction_id = escape_string($_POST['transaction_id'] ?? '');
    $gateway_id    = escape_string($_POST['gateway_id'] ?? '');

    // H1: CSRF validation
    if (!pp_phone_csrf_validate($_POST['csrf_token'] ?? '')) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Security Error',
            'message' => 'Invalid security token. Please refresh and try again.'
        ]);
    }

    if (empty($transaction_id) || empty($gateway_id)) {
        return pp_phone_safe_json(['status' => 'false', 'message' => 'Missing parameters.']);
    }

    // C4: Validate transaction exists AND status is initiated
    $params = [':ref' => $transaction_id, ':status' => 'initiated'];
    $response = json_decode(getData($db_prefix.'transaction', 
        'WHERE ref = :ref AND status = :status', '* FROM', $params), true);
    
    if ($response['status'] !== true) {
        // Already processed or doesn't exist — no-op
        return pp_phone_safe_json(['status' => 'true']);
    }

    pp_phone_set_expired($transaction_id);
    return pp_phone_safe_json(['status' => 'true']);
}

/**
 * Handle manual verification (fallback — transaction ID entry)
 */
function pp_phone_handle_verify($data = null) {
    global $db_prefix;

    $gateway_id     = escape_string($_POST['gateway_id'] ?? '');
    $transaction_id = escape_string($_POST['transaction_id'] ?? '');
    $phone          = escape_string($_POST['mobile_number'] ?? '');
    $trxid          = escape_string($_POST['trxid'] ?? '');
    $verify_mode    = escape_string($_POST['verify_mode'] ?? 'auto'); // auto | phone | trxid

    // H1: CSRF validation
    if (!pp_phone_csrf_validate($_POST['csrf_token'] ?? '')) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Security Error',
            'message' => 'Invalid security token. Please refresh and try again.'
        ]);
    }

    // Normalize phone
    if (!empty($phone)) {
        $phone = pp_phone_normalize($phone);
        if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
            return pp_phone_safe_json([
                'status'  => 'false',
                'title'   => 'Invalid Number',
                'message' => 'Please enter a valid Bangladeshi mobile number (01XXXXXXXXX).'
            ]);
        }
    }

    if (empty($gateway_id) || empty($transaction_id)) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Missing Information',
            'message' => 'Gateway ID and Transaction ID are required.'
        ]);
    }

    // Fetch transaction
    $params = [':ref' => $transaction_id, ':status' => 'initiated'];
    $response_txn = json_decode(getData($db_prefix.'transaction', 'WHERE ref = :ref AND status = :status', '* FROM', $params), true);

    if ($response_txn['status'] !== true) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Transaction Not Found',
            'message' => 'This transaction has already been processed or does not exist.'
        ]);
    }

    $transaction = $response_txn['response'][0];

    // H4: Verify gateway belongs to this transaction
    if (!empty($transaction['gateway_id']) && $transaction['gateway_id'] != $gateway_id) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Gateway Mismatch',
            'message' => 'This payment method does not belong to this transaction.'
        ]);
    }

    // C3: Server-side timeout check — 8 minutes max
    $elapsed = (time() - strtotime($transaction['created_date'])) / 60;
    if ($elapsed > 8) {
        pp_phone_set_expired($transaction_id);
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Session Expired',
            'message' => 'Payment verification window has expired.',
        ]);
    }

    // Fetch brand
    $params = [':brand_id' => $transaction['brand_id']];
    $response_brand = json_decode(getData($db_prefix.'brands', 'WHERE brand_id = :brand_id', '* FROM', $params), true);

    if ($response_brand['status'] !== true) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Brand Not Found',
            'message' => 'Invalid brand configuration.'
        ]);
    }

    $brand = $response_brand['response'][0];

    // Fetch gateway
    $params = [':gateway_id' => $gateway_id, ':brand_id' => $transaction['brand_id']];
    $response_gw = json_decode(getData($db_prefix.'gateways', 'WHERE gateway_id = :gateway_id AND brand_id = :brand_id AND status = "active"', '* FROM', $params), true);

    if ($response_gw['status'] !== true) {
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Gateway Not Found',
            'message' => 'This payment method is not available.'
        ]);
    }

    $gateway = $response_gw['response'][0];

    // C1: Derive sender_key from gateway slug (server-side, not client)
    $sender_key = pp_slug_to_sender_key($gateway['slug']);

    // C3: Server-side timeout check — 8 minutes max
    $elapsed = (time() - strtotime($transaction['created_date'])) / 60;
    if ($elapsed > 8) {
        pp_phone_set_expired($transaction_id);
        return pp_phone_safe_json([
            'status'  => 'false',
            'title'   => 'Session Expired',
            'message' => 'Payment verification window has expired.',
        ]);
    }

    // Calculate payable amount
    $currencyRates = [];
    $currencyRes = json_decode(getData($db_prefix.'currency', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $brand['brand_id']]), true);
    if (!empty($currencyRes['response'])) {
        foreach ($currencyRes['response'] as $c) {
            $currencyRates[$c['code']] = $c['rate'];
        }
    }

    $txnAmount   = money_sanitize($transaction['amount']);
    $txnCurrency = $transaction['currency'];
    $gwCurrency  = $gateway['currency'];

    if ($txnCurrency === $gwCurrency) {
        $convertedAmount = $txnAmount;
    } else {
        $convertedAmount = isset($currencyRates[$gwCurrency])
            ? money_div($txnAmount, $currencyRates[$gwCurrency])
            : "0";
    }

    $fixed_discount     = money_sanitize($gateway['fixed_discount']);
    $percentage_discount = money_sanitize($gateway['percentage_discount']);
    $fixed_charge       = money_sanitize($gateway['fixed_charge']);
    $percentage_charge  = money_sanitize($gateway['percentage_charge']);

    $pctDiscAmt = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
    $totalDiscount = money_add($fixed_discount, $pctDiscAmt, 8);

    $pctChgAmt = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
    $totalProcessingFee = money_add($fixed_charge, $pctChgAmt, 8);

    $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

    $pdo = connectDatabase();
    $verified = false;
    $matched_sms = null;
    $match_method = '';

    // MODE 1: Phone + amount with tolerance (fallback)
    if (!$verified && ($verify_mode === 'auto' || $verify_mode === 'phone') && !empty($phone)) {
        // H3: sms_data_validity — default 30 min
        $validity_minutes = intval($brand['sms_data_validity'] ?? 0);
        if ($validity_minutes < 1) $validity_minutes = 30;

        $findSql = 'SELECT id, trx_id, number, amount, sender, type 
                    FROM '.$db_prefix.'sms_data 
                    WHERE sender_key = :sender_key 
                      AND status = :approved 
                      AND created_date > DATE_SUB(NOW(), INTERVAL :validity MINUTE)
                    ORDER BY id DESC 
                    LIMIT 20';
        $findStmt = $pdo->prepare($findSql);
        $findStmt->execute([':sender_key' => $sender_key, ':approved' => 'approved', ':validity' => $validity_minutes]);
        $candidates = $findStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $sms) {
            $phone_to_match = (!empty($sms['sender']) && $sms['sender'] !== '--') ? $sms['sender'] : $sms['number'];

            if (!pp_phone_match($phone, $phone_to_match)) {
                continue;
            }

            // Check tolerance
            if (!verifyPaymentTolerance($convertedAmount, $sms['amount'], $brand['payment_tolerance'])) {
                continue;
            }

            // Atomic claim
            $claimStmt = $pdo->prepare('UPDATE '.$db_prefix.'sms_data SET status = :used WHERE id = :sms_id AND status = :approved');
            $claimStmt->execute([':used' => 'used', ':sms_id' => $sms['id'], ':approved' => 'approved']);

            if ($claimStmt->rowCount() > 0) {
                $verified = true;
                $matched_sms = $sms;
                $match_method = 'phone+amount-tolerance';
                break;
            }
        }
    }

    // MODE 2: Transaction ID (legacy fallback)
    if (!$verified && $verify_mode === 'trxid' && !empty($trxid)) {
        // Check duplicate trx_id (L4: brand-scoped)
        $checkStmt = $pdo->prepare('SELECT id FROM '.$db_prefix.'transaction WHERE trx_id = :trx_id AND brand_id = :brand_id LIMIT 1');
        $checkStmt->execute([':trx_id' => $trxid, ':brand_id' => $transaction['brand_id']]);
        if ($checkStmt->fetch()) {
            return pp_phone_safe_json([
                'status'  => 'false',
                'title'   => 'Duplicate Transaction ID',
                'message' => 'This Transaction ID is already used. Please provide a different one.'
            ]);
        }

        // Atomic claim by trx_id
        $claimSql = 'UPDATE '.$db_prefix.'sms_data SET status = :used, updated_date = :now 
                     WHERE sender_key = :sender_key AND trx_id = :trx_id AND status = :approved LIMIT 1';
        $claimStmt = $pdo->prepare($claimSql);
        $claimStmt->execute([':used' => 'used', ':now' => getCurrentDatetime('Y-m-d H:i:s'), ':sender_key' => $sender_key, ':trx_id' => $trxid, ':approved' => 'approved']);

        if ($claimStmt->rowCount() > 0) {
            $smsStmt = $pdo->prepare('SELECT id, trx_id, number, amount, sender, type FROM '.$db_prefix.'sms_data WHERE trx_id = :trx_id AND status = :used LIMIT 1');
            $smsStmt->execute([':trx_id' => $trxid, ':used' => 'used']);
            $matched_sms = $smsStmt->fetch(PDO::FETCH_ASSOC);

            if ($matched_sms && verifyPaymentTolerance($convertedAmount, $matched_sms['amount'], $brand['payment_tolerance'])) {
                $verified = true;
                $match_method = 'trxid';
            } else {
                // Revert claim (null check for edge case)
                if ($matched_sms) {
                    $revertStmt = $pdo->prepare('UPDATE '.$db_prefix.'sms_data SET status = :approved WHERE id = :sms_id AND status = :used');
                    $revertStmt->execute([':approved' => 'approved', ':sms_id' => $matched_sms['id']]);
                }
                $matched_sms = null;
            }
        }
    }

    // Process verified payment
    if ($verified && $matched_sms) {
        $moreinfo = [
            ['label' => 'Provider',      'value' => ucfirst($sender_key)],
            ['label' => 'Mobile Number',  'value' => $matched_sms['number']],
            ['label' => 'Match Method',   'value' => $match_method],
        ];

        pp_set_transaction_status(
            $transaction['ref'],
            'completed',
            $gateway_id,
            $matched_sms['trx_id'],
            $moreinfo,
            [
                'sender'      => $matched_sms['number'],
                'sender_key'  => $sender_key,
                'sender_type' => $matched_sms['type'],
            ]
        );

        // Webhook
        pp_phone_send_webhook($transaction, $gateway, $brand, $matched_sms, $sender_key);

        return pp_phone_safe_json([
            'status'   => 'true',
            'title'    => 'Payment Verified',
            'message'  => 'Your payment has been verified successfully.',
            'trx_id'   => $matched_sms['trx_id'],
            'redirect'  => pp_checkout_address($transaction['ref']),
        ]);
    }

    return pp_phone_safe_json([
        'status'  => 'false',
        'title'   => 'Payment Not Found',
        'message' => 'No matching payment found. Please check your phone number and try again, or enter the Transaction ID manually.',
    ]);
}

/**
 * Render the 2-step phone verification form
 * Called from pp_gateway_render() when brand verification_method = 'phone_number'
 */
function pp_phone_render_form($data) {
    $gateway_id  = htmlspecialchars($data['gateway']['gateway_id'] ?? '');
    $txn_ref     = htmlspecialchars($data['transaction']['ref'] ?? '');
    $amount      = htmlspecialchars($data['transaction']['local_net_amount'] ?? '0');
    $currency    = htmlspecialchars($data['transaction']['local_currency'] ?? '');
    $payable     = htmlspecialchars(number_format((float)($data['transaction']['amount'] ?? 0), 2));
    $discount    = htmlspecialchars($data['transaction']['discount_amount'] ?? '0');
    $fee         = htmlspecialchars($data['transaction']['processing_fee'] ?? '0');

    // Language strings
    $lang = $data['lang'] ?? [];
    $lbl_phone       = $lang['mobile_number'] ?? 'Mobile Number';
    $lbl_next        = 'Next →';
    $lbl_verify      = $lang['verify'] ?? 'Verify';
    $lbl_trxid       = $lang['transaction_id'] ?? 'Transaction ID';
    $lbl_enter_trxid = $lang['enter_transaction_id'] ?? 'Enter Transaction ID';
    $lbl_inst_title  = 'Payment Instructions';
    $lbl_waiting     = 'Waiting for your payment...';
    $lbl_fallback    = 'Payment not detected?';
    $lbl_use_trxid   = 'Use Transaction ID instead';
    $lbl_keep_window = 'Please do not close this window. We will automatically verify your payment once it\'s complete.';

    echo '
    <div id="pp-phone-step1" class="pp-phone-form">
        <div class="form-group">
            <label class="form-label">'.$lbl_phone.'</label>
            <div class="form-control-wrap">
                <input type="tel" id="pp-phone-input" class="form-control" placeholder="Enter sender number" maxlength="11">
            </div>
        </div>
        <button id="pp-phone-next" class="btn-phone-next">'.$lbl_next.'</button>
    </div>
    <div id="pp-phone-step2" style="display:none"></div>';

    /* ── Fallback form — rendered as separate .hz-form-card (stays in place, no JS move) ── */
    echo '
    <div class="hz-form-card" id="pp-phone-fallback-form" style="display:none">
        <div class="hz-form-body">
            <div class="form-group">
                <label class="form-label">'.$lbl_trxid.'</label>
                <input type="text" id="pp-phone-trxid-input" class="form-control" placeholder="'.htmlspecialchars($lbl_enter_trxid).'">
            </div>
            <button type="button" id="pp-phone-trxid-submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>'.$lbl_verify.'</span>
            </button>
        </div>
    </div>';

    /* ── Wait area HTML (built once, inserted via JS) — matches qr-gateway style ── */
    $waitArea = '<div class="bz-waiting" id="pp-phone-wait-area">'
        .'<div class="bz-waiting-row">'
            .'<div class="bz-spinner"></div>'
            .'<span class="bz-waiting-label">'.$lbl_waiting.'</span>'
            .'<span class="bz-timer" id="pp-phone-countdown">8:00</span>'
        .'</div>'
        .'<div class="bz-warning" id="pp-phone-warning" style="display:none">'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 9v4"/><path d="M12 16v.01"/></svg>'
            .'Time expired. Please try again.'
        .'</div>'
        .'<div class="bz-fallback" id="pp-phone-fallback-wrap" style="display:none">'
            .'<span class="bz-fallback-text">'.$lbl_fallback.' </span>'
            .'<span class="bz-fallback-link" id="pp-phone-fallback-link">'.$lbl_use_trxid.'</span>'
        .'</div>'
        .'<div class="bz-note">'.$lbl_keep_window.'</div>'
    .'</div>';
    $waitAreaJs = json_encode($waitArea);

    echo '<script data-cfasync="false">
    (function(){
        var errSvg=\'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e53e3e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 9v4"/><path d="M12 16v.01"/></svg>\';
        var pollTimer=null, countdownTimer=null, remaining=480;
        var gatewayId="'.$gateway_id.'", txnRef="'.$txn_ref.'";
        var csrfToken="'.htmlspecialchars(pp_phone_csrf_token()).'";
        var sessionKey="pp_phone_"+txnRef;
        var timeKey="pp_phone_time_"+txnRef;
        var fallbackKey="pp_phone_fb_"+txnRef;
        var nextBtn=document.getElementById("pp-phone-next");
        var step1=document.getElementById("pp-phone-step1");
        var step2=document.getElementById("pp-phone-step2");
        var moved=false;
        if(!nextBtn) return;

        function moveIntoInstCard(){
            if(moved) return;
            var instCard=document.getElementById("hz-inst-section");
            if(!instCard) return;
            var content=instCard.querySelector("#hz-instructions-content")||instCard;
            var instrList=content.querySelector("ol.payment-instructions");
            if(instrList) instrList.style.display="none";
            content.appendChild(step1);
            content.appendChild(step2);
            /* Move fallback form outside #hz-form-section so it stays visible */
            var ff=document.getElementById("pp-phone-fallback-form");
            if(ff){
                var fs=document.getElementById("hz-form-section");
                if(fs&&fs.parentNode) fs.parentNode.insertBefore(ff,fs.nextSibling);
            }
            moved=true;
        }

        function goToStep1(){
            clearInterval(pollTimer);clearInterval(countdownTimer);
            step2.style.display="none";
            step1.style.display="block";
            var instCard=document.getElementById("hz-inst-section");
            if(instCard){
                var title=instCard.querySelector(".hz-inst-title");
                if(title) title.style.display="none";
                var content=instCard.querySelector("#hz-instructions-content")||instCard;
                var instrList=content.querySelector("ol.payment-instructions");
                if(instrList) instrList.style.display="none";
            }
            var wa=document.getElementById("pp-phone-wait-area");
            if(wa)wa.remove();
            /* Hide & reset fallback form */
            var ff=document.getElementById("pp-phone-fallback-form");
            if(ff){ff.style.display="none";}
            var fi=document.getElementById("pp-phone-trxid-input");
            if(fi){fi.value="";}
            try{sessionStorage.removeItem(sessionKey);sessionStorage.removeItem(timeKey);sessionStorage.removeItem(fallbackKey);}catch(e){}
        }

        function goToStep2(phone){
            step1.style.display="none";
            step2.style.display="block";
            interceptBack=true;
            /* Push history so phone back button goes to step 1 instead of checkout */
            try{history.pushState({ppStep2:phone},"");}catch(e){}
            var instCard=document.getElementById("hz-inst-section");
            if(instCard){
                var title=instCard.querySelector(".hz-inst-title");
                if(title) title.style.display="";
                var content=instCard.querySelector("#hz-instructions-content")||instCard;
                var instrList=content.querySelector("ol.payment-instructions");
                if(instrList) instrList.style.display="";
                if(!document.getElementById("pp-phone-wait-area")){
                    content.insertAdjacentHTML("beforeend",'.$waitAreaJs.');
                }
            }
            bindEvents();
            try{sessionStorage.setItem(sessionKey,phone);}catch(e){}
            startPolling(phone);startCountdown();
        }

        function bindEvents(){
            var fb=document.getElementById("pp-phone-fallback-link");
            if(fb) fb.onclick=function(){
                var ff=document.getElementById("pp-phone-fallback-form");
                if(ff) ff.style.display="block";
                try{sessionStorage.setItem(fallbackKey,"1");}catch(e){}
            };
            var ts=document.getElementById("pp-phone-trxid-submit");
            if(ts) ts.onclick=function(){
                var trxid=(document.getElementById("pp-phone-trxid-input").value||"").trim();
                if(!trxid){createToast({title:hzT("Transaction ID Required"),description:hzT("Please enter your Transaction ID."),svg:errSvg,timeout:5000});return;}
                clearInterval(pollTimer);clearInterval(countdownTimer);
                var fd=new FormData();fd.append("action-v2","pp-phone-verify");
                fd.append("gateway_id",gatewayId);fd.append("transaction_id",txnRef);
                fd.append("trxid",trxid);fd.append("verify_mode","trxid");
                fd.append("csrf_token",csrfToken);
                this.innerHTML="Verifying...";
                var self=this;
                fetch("",{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
                    if(d.status==="true"){try{sessionStorage.removeItem(sessionKey);sessionStorage.removeItem(timeKey);sessionStorage.removeItem(fallbackKey);}catch(e){}
                    window.location.href=window.location.pathname+"?gateway="+gatewayId;
                    }else{createToast({title:hzT(d.title||"Verification Failed"),description:hzT(d.message||"No match found."),svg:errSvg,timeout:6000});self.innerHTML="'.htmlspecialchars($lbl_verify).'";}
                }).catch(function(){createToast({title:hzT("Error"),description:hzT("Something went wrong. Please try again."),svg:errSvg,timeout:6000});self.innerHTML="'.htmlspecialchars($lbl_verify).'";});
            };
        }

        /* Hook nav back button — go to step 1 if in step 2 */
        var interceptBack=false;
        var checkoutUrl=window.location.pathname;
        function hookNavBack(){
            var navBack=document.querySelector(".hz-nav-cancel");
            if(!navBack) return;
            /* Save original checkout URL from inline onclick */
            var origOnclick=navBack.getAttribute("onclick");
            if(origOnclick && origOnclick.indexOf("location.href")!==-1){
                var eq=origOnclick.indexOf("=");
                if(eq!==-1){
                    var url=origOnclick.substring(eq+1).replace(/^[\'"\s]+|[\'"\s;]+$/g,"");
                    if(url) checkoutUrl=url;
                }
            }
            navBack.onclick=function(e){
                if(interceptBack){
                    e.preventDefault();e.stopImmediatePropagation();
                    interceptBack=false;
                    goToStep1();
                    return false;
                }
                /* Navigate to checkout page */
                window.location.href=checkoutUrl;
            };
        }
        hookNavBack();

        /* Phone back button goes to step 1 instead of checkout */
        window.addEventListener("popstate",function(e){
            if(step2.style.display!=="none" && step1.style.display==="none"){
                e.preventDefault();
                goToStep1();
            }
            }
        });

        nextBtn.onclick=function(){
            var phone=(document.getElementById("pp-phone-input").value||"").trim();
            if(!phone){createToast({title:hzT("Mobile Number Required"),description:hzT("Please enter your mobile number."),svg:errSvg,timeout:5000});return;}
            if(!/^01[3-9]\d{8}$/.test(phone)){createToast({title:hzT("Invalid Number"),description:hzT("Please enter a valid Bangladeshi mobile number (01XXXXXXXXX)."),svg:errSvg,timeout:5000});return;}
            goToStep2(phone);
        };

        function startPolling(phone){
            pollTimer=setInterval(function(){
                var fd=new FormData();fd.append("action-v2","pp-phone-poll");
                fd.append("gateway_id",gatewayId);fd.append("transaction_id",txnRef);
                fd.append("mobile_number",phone);
                fd.append("csrf_token",csrfToken);
                fetch("",{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
                    if(d.status==="true"){clearInterval(pollTimer);clearInterval(countdownTimer);
                    try{sessionStorage.removeItem(sessionKey);sessionStorage.removeItem(timeKey);sessionStorage.removeItem(fallbackKey);}catch(e){}
                    window.location.href=window.location.pathname+"?gateway="+gatewayId;}
                }).catch(function(){});
            },3000);
        }

        function startCountdown(){
            /* Countdown protection: calculate remaining from stored start time */
            try{
                var stored=timeKey?sessionStorage.getItem(timeKey):null;
                var now=Math.floor(Date.now()/1000);
                if(stored){
                    var elapsed=now-parseInt(stored,10);
                    remaining=Math.max(0,480-elapsed);
                } else {
                    sessionStorage.setItem(timeKey,now);
                    remaining=480;
                }
            }catch(e){remaining=480;}

            if(remaining<=0){
                clearInterval(pollTimer);
                try{sessionStorage.removeItem(sessionKey);sessionStorage.removeItem(timeKey);sessionStorage.removeItem(fallbackKey);}catch(e){}
                var fd=new FormData();fd.append("action-v2","pp-phone-cancel");
                fd.append("transaction_id",txnRef);fd.append("gateway_id",gatewayId);
                fd.append("csrf_token",csrfToken);
                fetch("",{method:"POST",body:fd});
                window.location.href=window.location.pathname+"?gateway="+gatewayId;
                return;
            }
            /* Set correct countdown value immediately — no blink */
            var cd0=document.getElementById("pp-phone-countdown");
            if(cd0){var m0=Math.floor(remaining/60),s0=remaining%60;cd0.textContent=(m0<10?"0":"")+m0+":"+(s0<10?"0":"")+s0;}

            countdownTimer=setInterval(function(){
                remaining--;var m=Math.floor(remaining/60),s=remaining%60;
                var cd=document.getElementById("pp-phone-countdown");
                var warn=document.getElementById("pp-phone-warning");
                if(cd)cd.textContent=(m<10?"0":"")+m+":"+(s<10?"0":"")+s;
                if(remaining<=0){
                    clearInterval(countdownTimer);clearInterval(pollTimer);
                    try{sessionStorage.removeItem(sessionKey);sessionStorage.removeItem(timeKey);sessionStorage.removeItem(fallbackKey);}catch(e){}
                    var fd=new FormData();fd.append("action-v2","pp-phone-cancel");
                    fd.append("transaction_id",txnRef);fd.append("gateway_id",gatewayId);
                    fd.append("csrf_token",csrfToken);
                    fetch("",{method:"POST",body:fd});
                    window.location.href=window.location.pathname+"?gateway="+gatewayId;
                }
            },1000);
            /* Show fallback link after 30s (from start, not from now) */
            var showAt=30-elapsedFromStart();
            if(showAt<=0){var fl=document.getElementById("pp-phone-fallback-wrap");if(fl)fl.style.display="block";}
            else setTimeout(function(){var fl=document.getElementById("pp-phone-fallback-wrap");if(fl)fl.style.display="block";},showAt*1000);
        }

        function elapsedFromStart(){
            try{var s=sessionStorage.getItem(timeKey);return s?Math.floor(Date.now()/1000)-parseInt(s,10):0;}catch(e){return 0;}
        }

        /* ── Init: wait for #hz-inst-section, move phone elements into it ── */
        function init(){
            moveIntoInstCard();
            /* Hide title + instructions on initial load (step 1) */
            var instCard=document.getElementById("hz-inst-section");
            if(instCard){
                var title=instCard.querySelector(".hz-inst-title");
                if(title) title.style.display="none";
                var instrList=instCard.querySelector("ol.payment-instructions");
                if(instrList) instrList.style.display="none";
            }
            try{
                var sp=sessionStorage.getItem(sessionKey);
                if(sp && step1.style.display!=="none") goToStep2(sp);
                /* Restore fallback form visibility */
                if(sessionStorage.getItem(fallbackKey)==="1"){
                    var ff=document.getElementById("pp-phone-fallback-form");
                    if(ff) ff.style.display="block";
                }
            }catch(e){}
        }

        if(document.getElementById("hz-inst-section")){
            init();
        } else {
            var done=false;
            var obs=new MutationObserver(function(){
                if(document.getElementById("hz-inst-section") && !done){
                    done=true; obs.disconnect(); init();
                }
            });
            obs.observe(document.body,{childList:true,subtree:true});
            setTimeout(function(){
                if(!done){done=true; obs.disconnect(); if(document.getElementById("hz-inst-section")) init();}
            },500);
        }
    })();
    </script>';
}
