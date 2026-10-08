<?php
/**
 * Support Tickets
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

$error = null;
$showNewModal = isset($_GET['new']);

// Handle ticket creation or user reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
        $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high']) ? $_POST['priority'] : 'medium';
        $message = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            $error = "Please provide both subject and details for your ticket.";
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO tickets (user_id, order_id, subject, status, priority)
                    VALUES (:uid, :oid, :subj, 'open', :priority)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'oid' => $orderId,
                    'subj' => $subject,
                    'priority' => $priority
                ]);
                $ticketId = (int)$db->lastInsertId();

                $msgStmt = $db->prepare("
                    INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message)
                    VALUES (:tid, :uid, 0, :msg)
                ");
                $msgStmt->execute([
                    'tid' => $ticketId,
                    'uid' => $user['id'],
                    'msg' => $message
                ]);

                $db->commit();
                set_flash('success', "Ticket #T{$ticketId} created successfully. Our team will review it shortly.");
                redirect('/user/tickets.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create ticket: " . $e->getMessage();
            }
        }
    } elseif ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        // Verify ticket belongs to user
        $check = $db->prepare("SELECT id FROM tickets WHERE id = :tid AND user_id = :uid LIMIT 1");
        $check->execute(['tid' => $ticketId, 'uid' => $user['id']]);

        if ($check->fetch() && !empty($message)) {
            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message) VALUES (:tid, :uid, 0, :msg)")->execute([
                    'tid' => $ticketId,
                    'uid' => $user['id'],
                    'msg' => $message
                ]);
                $db->prepare("UPDATE tickets SET status = 'open', updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $ticketId]);
                $db->commit();
                set_flash('success', "Message sent on ticket #T{$ticketId}.");
                redirect('/user/tickets.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to post reply: " . $e->getMessage();
            }
        }
    }
}

$statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
$sql = "SELECT * FROM tickets WHERE user_id = :uid";
$params = ['uid' => $user['id']];

if (in_array($statusFilter, ['open', 'in_progress', 'closed'])) {
    $sql .= " AND status = :status";
    $params['status'] = $statusFilter;
}
$sql .= " ORDER BY updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Fetch messages for each ticket
$ticketMessages = [];
if (!empty($tickets)) {
    $tIds = array_column($tickets, 'id');
    $placeholders = implode(',', array_fill(0, count($tIds), '?'));
    $msgStmt = $db->prepare("SELECT * FROM ticket_messages WHERE ticket_id IN ($placeholders) ORDER BY created_at ASC");
    $msgStmt->execute($tIds);
    $allMsgs = $msgStmt->fetchAll();
    foreach ($allMsgs as $m) {
        $ticketMessages[$m['ticket_id']][] = $m;
    }
}

$pageTitle = "Support Tickets - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
    </div>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="flex items-center justify-between pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">Support</h2>
                <p class="text-xs text-slate-500 mt-0.5">24/7 dedicated support for all your queries</p>
            </div>
            <button type="button" onclick="document.getElementById('newTicketSection').classList.toggle('hidden')" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                + Create Ticket
            </button>
        </div>

        <?php if ($error): ?>
            <div class="my-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Create Ticket Collapsible Form -->
        <div id="newTicketSection" class="<?= $showNewModal || !empty($_GET['order_id']) ? '' : 'hidden' ?> my-6 p-6 rounded-2xl bg-blue-50/50 border border-blue-200">
            <h3 class="text-base font-extrabold text-slate-900 mb-4">Open a New Support Ticket</h3>
            <form action="/user/tickets.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="create_ticket">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Subject</label>
                        <input type="text" name="subject" required placeholder="e.g. Order not started yet" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Related Order ID (Optional)</label>
                        <input type="number" name="order_id" value="<?= e($_GET['order_id'] ?? '') ?>" placeholder="e.g. 10254" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Priority</label>
                    <select name="priority" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <option value="low">Low Priority</option>
                        <option value="medium" selected>Medium Priority</option>
                        <option value="high">High / Urgent Priority</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Message Description</label>
                    <textarea name="message" rows="4" required placeholder="Please describe your issue in detail..." class="w-full p-4 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600"></textarea>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <button type="button" onclick="document.getElementById('newTicketSection').classList.add('hidden')" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-200 rounded-xl">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md">Submit Ticket</button>
                </div>
            </form>
        </div>

        <!-- Filter Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto py-4">
            <a href="/user/tickets.php?status=all" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">All</a>
            <a href="/user/tickets.php?status=open" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'open' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Open</a>
            <a href="/user/tickets.php?status=in_progress" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'in_progress' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">In Progress</a>
            <a href="/user/tickets.php?status=closed" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $statusFilter === 'closed' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Closed</a>
        </div>

        <!-- Tickets List -->
        <?php if (empty($tickets)): ?>
            <div class="py-16 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 mx-auto flex items-center justify-center text-2xl mb-3">💬</div>
                <h3 class="text-sm font-bold text-slate-900">No support tickets found</h3>
                <p class="text-xs text-slate-500 mt-1">Need help with an order or payment? Create a ticket anytime.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4 mt-2">
                <?php foreach ($tickets as $t): 
                    $tId = (int)$t['id'];
                    $msgs = $ticketMessages[$tId] ?? [];
                ?>
                    <div class="p-5 rounded-2xl border border-slate-200/80 hover:border-slate-300 bg-white transition-all">
                        <div class="flex items-center justify-between cursor-pointer" onclick="document.getElementById('thread-<?= $tId ?>').classList.toggle('hidden')">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold font-mono text-slate-400">#T<?= $tId ?></span>
                                    <h4 class="text-sm font-extrabold text-slate-900"><?= e($t['subject']) ?></h4>
                                </div>
                                <div class="flex items-center gap-3 text-[11px] text-slate-400 mt-1">
                                    <span><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></span>
                                    <?php if ($t['order_id']): ?>
                                        <span>• Order #<?= (int)$t['order_id'] ?></span>
                                    <?php endif; ?>
                                    <span>• <?= count($msgs) ?> message(s)</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider <?= $t['status'] === 'open' ? 'bg-amber-50 text-amber-600 border border-amber-200' : ($t['status'] === 'in_progress' ? 'bg-blue-50 text-blue-600 border border-blue-200' : 'bg-slate-100 text-slate-600') ?>">
                                    <?= e(str_replace('_', ' ', $t['status'])) ?>
                                </span>
                                <span class="text-xs text-blue-600 font-bold hover:underline">View Conversation &darr;</span>
                            </div>
                        </div>

                        <!-- Ticket Conversation Thread -->
                        <div id="thread-<?= $tId ?>" class="hidden mt-4 pt-4 border-t border-slate-100 space-y-3">
                            <?php foreach ($msgs as $msg): ?>
                                <div class="p-3.5 rounded-xl text-xs <?= $msg['is_admin'] ? 'bg-blue-50 border border-blue-200 text-blue-950 ml-4' : 'bg-slate-50 border border-slate-200 text-slate-800 mr-4' ?>">
                                    <div class="flex items-center justify-between font-bold mb-1">
                                        <span class="<?= $msg['is_admin'] ? 'text-blue-700' : 'text-slate-700' ?>">
                                            <?= $msg['is_admin'] ? '🛡️ Support Team' : '👤 You' ?>
                                        </span>
                                        <span class="text-[10px] text-slate-400 font-normal"><?= date('d M, h:i A', strtotime($msg['created_at'])) ?></span>
                                    </div>
                                    <p class="leading-relaxed whitespace-pre-wrap"><?= e($msg['message']) ?></p>
                                </div>
                            <?php endforeach; ?>

                            <!-- Reply input for user -->
                            <?php if ($t['status'] !== 'closed'): ?>
                                <form action="/user/tickets.php" method="POST" class="pt-2 flex gap-2">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="reply_ticket">
                                    <input type="hidden" name="ticket_id" value="<?= $tId ?>">
                                    <input type="text" name="message" required placeholder="Type your response to support..." class="flex-1 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-sm">Send</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
