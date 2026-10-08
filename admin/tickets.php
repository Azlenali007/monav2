<?php
/**
 * Admin Support Tickets
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply_ticket') {
    CSRF::verifyOrAbort();
    $ticketId = (int)$_POST['ticket_id'];
    $reply = trim($_POST['message'] ?? '');
    $newStatus = $_POST['status'] ?? 'in_progress';

    if (!empty($reply) && $ticketId > 0) {
        $db->beginTransaction();
        $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message) VALUES (:tid, :uid, 1, :msg)")->execute([
            'tid' => $ticketId,
            'uid' => Auth::id(),
            'msg' => $reply
        ]);
        $db->prepare("UPDATE tickets SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['st' => $newStatus, 'id' => $ticketId]);
        $db->commit();
        set_flash('success', "Reply posted to Ticket #T{$ticketId}.");
        redirect('/admin/tickets.php');
    }
}

$tickets = $db->query("
    SELECT t.*, u.username
    FROM tickets t
    JOIN users u ON t.user_id = u.id
    ORDER BY t.updated_at DESC
")->fetchAll();

$pageTitle = "Manage Tickets - Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <h2 class="text-2xl font-extrabold text-slate-900 pb-6 border-b border-slate-100">Customer Support Tickets</h2>

        <div class="divide-y divide-slate-100 mt-4">
            <?php foreach ($tickets as $t): ?>
                <div class="py-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-mono text-xs text-slate-400">#T<?= (int)$t['id'] ?> • <?= e($t['username']) ?></span>
                            <h3 class="text-sm font-extrabold text-slate-900 mt-0.5"><?= e($t['subject']) ?></h3>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $t['status'] === 'open' ? 'bg-amber-50 text-amber-600' : ($t['status'] === 'in_progress' ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-600') ?>">
                            <?= e(str_replace('_', ' ', $t['status'])) ?>
                        </span>
                    </div>

                    <!-- Admin Reply Form -->
                    <form action="/admin/tickets.php" method="POST" class="flex gap-2">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="reply_ticket">
                        <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                        <input type="text" name="message" required placeholder="Write quick admin response to customer..." class="flex-1 px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                        <select name="status" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold">
                            <option value="in_progress">In Progress</option>
                            <option value="closed">Closed / Resolved</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold shadow-sm">Send</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
