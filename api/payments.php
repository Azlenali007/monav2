<?php
/**
 * Payment Gateway Webhooks (Razorpay Webhook Listener)
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

// Verify webhook signature
if (!empty($signature) && !empty($payload)) {
    $expectedSignature = hash_hmac('sha256', $payload, RAZORPAY_KEY_SECRET);
    if (!hash_equals($expectedSignature, $signature)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    $event = json_decode($payload, true);
    if (($event['event'] ?? '') === 'payment.captured') {
        $payment = $event['payload']['payment']['entity'] ?? null;
        if ($payment) {
            $amount = ((float)$payment['amount']) / 100; // Razorpay provides paise
            $txnId = $payment['id'];
            $email = $payment['email'] ?? '';

            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Prevent duplicate credits
                $check = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
                $check->execute(['txnid' => $txnId]);
                if (!$check->fetch()) {
                    $db->beginTransaction();
                    $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid")->execute(['amt' => $amount, 'uid' => $user['id']]);
                    $db->prepare("INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note) VALUES (:uid, 'deposit', :amt, 'Razorpay', :txnid, 'completed', 'Webhook auto credit')")->execute([
                        'uid' => $user['id'],
                        'amt' => $amount,
                        'txnid' => $txnId
                    ]);
                    $db->commit();
                }
            }
        }
    }
}

echo json_encode(['status' => 'ok']);
