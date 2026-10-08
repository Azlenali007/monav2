<?php
/**
 * Protected AI Ticket Reply Assistant Backend Endpoint
 * Architecture for future AI-assisted customer ticket suggestions.
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Strict Role-Based Access Control (Admin Only)
if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Administrator privileges required.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. POST required.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$ticketId = (int)($input['ticket_id'] ?? 0);

if ($ticketId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valid ticket ID is required.'
    ]);
    exit;
}

$db = Database::getConnection();

// Load ticket details and customer info
$stmt = $db->prepare("
    SELECT t.*, u.username, u.email
    FROM tickets t
    JOIN users u ON t.user_id = u.id
    WHERE t.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Ticket not found.'
    ]);
    exit;
}

// Load full ticket message history
$stmtMsg = $db->prepare("
    SELECT tm.*, u.username, u.role
    FROM ticket_messages tm
    JOIN users u ON tm.user_id = u.id
    WHERE tm.ticket_id = :tid
    ORDER BY tm.created_at ASC
");
$stmtMsg->execute(['tid' => $ticketId]);
$messages = $stmtMsg->fetchAll();

// Load relevant order context if associated
$orderContext = null;
if (!empty($ticket['order_id'])) {
    $stmtOrder = $db->prepare("
        SELECT o.id, o.link, o.quantity, o.charge, o.status, o.start_count, o.remains, s.name AS service_name, s.speed
        FROM orders o
        JOIN services s ON o.service_id = s.id
        WHERE o.id = :oid
        LIMIT 1
    ");
    $stmtOrder->execute(['oid' => (int)$ticket['order_id']]);
    $orderContext = $stmtOrder->fetch();
}

// Prepare Sanitized Context for Future AI Processing
// Defense against prompt injection: strip system instruction delimiters & sanitize content
$sanitizedSubject = htmlspecialchars_decode(strip_tags((string)$ticket['subject']));
$conversationHistory = [];

foreach ($messages as $m) {
    $senderRole = !empty($m['is_admin']) ? 'Support Specialist' : 'Customer';
    $cleanMessage = htmlspecialchars_decode(strip_tags((string)$m['message']));
    // Limit individual message length in context to prevent token overflows
    if (mb_strlen($cleanMessage) > 1500) {
        $cleanMessage = mb_substr($cleanMessage, 0, 1500) . '... [truncated]';
    }
    $conversationHistory[] = [
        'sender' => $senderRole,
        'timestamp' => $m['created_at'],
        'text' => $cleanMessage
    ];
}

// Check if AI Ticket Assistant is enabled and configured in Admin Settings
$aiEnabled = get_setting('ai_enabled', '0') === '1';
$aiApiKey = get_setting('ai_api_key', '');
$aiProvider = get_setting('ai_provider', 'openai');
$aiModel = get_setting('ai_model', 'gpt-4o');
$aiMaxTokens = (int)get_setting('ai_max_tokens', '500');
$aiSystemPrompt = get_setting('ai_system_prompt', 'You are a professional customer support assistant for an SMM Panel platform. Be polite, concise, and helpful. Provide clear resolution steps for order, payment, and service queries.');

// If AI is not enabled or API key is not configured, return clear unconfigured state.
// RULE: Do not fake an AI response, do not hardcode AI text, do not pretend it works.
if (!$aiEnabled || empty($aiApiKey)) {
    echo json_encode([
        'success' => false,
        'configured' => false,
        'message' => 'AI Reply Assistant is not configured yet. Please configure the AI Provider and API Key in Admin Settings.',
        'ticket_id' => $ticketId,
        'context_ready' => true,
        'meta' => [
            'messages_count' => count($messages),
            'has_order_context' => ($orderContext !== null)
        ]
    ]);
    exit;
}

// FUTURE PROVIDER INTEGRATION ARCHITECTURE:
// When a live AI provider (OpenAI, Gemini, Anthropic) is connected via server-side cURL:
// 1. Build sanitized payload with $aiSystemPrompt + $conversationHistory + $orderContext.
// 2. Call external AI API with timeout and error handling.
// 3. Return draft suggestion to Admin for manual review and editing.
// Note: Since real provider is not enabled/connected yet, safe fallback response is returned.

echo json_encode([
    'success' => false,
    'configured' => false,
    'message' => 'AI Reply Assistant is not configured yet.',
    'ticket_id' => $ticketId
]);
exit;
