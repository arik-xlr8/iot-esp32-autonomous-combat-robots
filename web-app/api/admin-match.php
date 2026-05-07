<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/live-refresh.php';

function getAdminMatchPayload($pdo) {
    $stmt = $pdo->query("
        SELECT *
        FROM matches
        ORDER BY match_id DESC
        LIMIT 1
    ");
    $match = $stmt->fetch();

    if (!$match) {
        return [
            'match' => null,
            'bets' => []
        ];
    }

    $stmt = $pdo->prepare("
        SELECT b.*, u.username
        FROM bets b
        INNER JOIN users u ON u.user_id = b.user_id
        WHERE b.match_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([(int)$match['match_id']]);
    $bets = $stmt->fetchAll();

    return [
        'match' => [
            'matchId' => (int)$match['match_id'],
            'title' => $match['title'],
            'status' => $match['status'],
            'winner' => $match['winner'],
            'totalPool' => (int)$match['total_pool'],
            'chadgptPool' => (int)$match['chadgpt_pool'],
            'grokozillaPool' => (int)$match['grokozilla_pool'],
            'totalBetsCount' => (int)$match['total_bets_count'],
            'chadgptBetsCount' => (int)$match['chadgpt_bets_count'],
            'grokozillaBetsCount' => (int)$match['grokozilla_bets_count']
        ],
        'bets' => array_map(function ($bet) {
            return [
                'username' => $bet['username'],
                'selectedRobot' => $bet['selected_robot'],
                'amount' => (int)$bet['amount'],
                'multiplierAtBet' => (float)$bet['multiplier_at_bet'],
                'finalMultiplier' => $bet['final_multiplier'] !== null ? (float)$bet['final_multiplier'] : null,
                'isWon' => $bet['is_won'] !== null ? (bool)$bet['is_won'] : null,
                'payoutAmount' => $bet['payout_amount'] !== null ? (int)$bet['payout_amount'] : null,
                'refunded' => (bool)$bet['refunded'],
                'createdAt' => $bet['created_at']
            ];
        }, $bets)
    ];
}

function respondWithAdminMatch($pdo, $message = null) {
    $payload = getAdminMatchPayload($pdo);
    $payload['success'] = true;

    if ($message !== null) {
        $payload['message'] = $message;
    }

    jsonResponse($payload);
}

function fetchLockedMatch($pdo, $matchId) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM matches
        WHERE match_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$matchId]);
    return $stmt->fetch();
}

function writeAdminLog($pdo, $matchId, $adminId, $action, $description = null) {
    $stmt = $pdo->prepare("
        INSERT INTO match_logs (match_id, admin_id, action, description)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$matchId, $adminId ?: null, $action, $description]);
}

function hasOpenMatch($pdo) {
    $stmt = $pdo->query("
        SELECT match_id
        FROM matches
        WHERE status NOT IN ('paid', 'cancelled')
        LIMIT 1
    ");

    return (bool)$stmt->fetch();
}

try {
    $adminId = requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respondWithAdminMatch($pdo);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'message' => 'Only GET and POST methods are allowed'
        ], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $action = $input['action'] ?? '';
    $matchId = (int)($input['matchId'] ?? 0);

    if ($action === 'create') {
        if (hasOpenMatch($pdo)) {
            jsonResponse([
                'success' => false,
                'message' => 'There is already an active match. Pay or cancel it before creating a new one.'
            ], 400);
        }

        $title = trim($input['title'] ?? '');

        if ($title === '') {
            $title = 'ChadGPT vs GROKOZILLA';
        }

        $stmt = $pdo->prepare("
            INSERT INTO matches (
                title,
                status,
                total_pool,
                chadgpt_pool,
                grokozilla_pool,
                total_bets_count,
                chadgpt_bets_count,
                grokozilla_bets_count,
                created_by_admin_id
            )
            VALUES (?, 'created', 0, 0, 0, 0, 0, 0, ?)
        ");
        $stmt->execute([$title, $adminId ?: null]);

        $createdMatchId = (int)$pdo->lastInsertId();
        writeAdminLog($pdo, $createdMatchId, $adminId, 'create', 'Match created');

        triggerLiveRefresh(3000);
        respondWithAdminMatch($pdo, 'Match created');
    }

    if ($matchId <= 0) {
        jsonResponse([
            'success' => false,
            'message' => 'matchId is required'
        ], 400);
    }

    if ($action === 'open' || $action === 'lock') {
        $nextStatus = $action === 'open' ? 'betting_open' : 'betting_locked';
        $allowedStatus = $action === 'open' ? ['created', 'betting_locked'] : ['betting_open'];

        $pdo->beginTransaction();
        $match = fetchLockedMatch($pdo, $matchId);

        if (!$match) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Match not found'], 404);
        }

        if (!in_array($match['status'], $allowedStatus, true)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Match status cannot be changed with this action'], 400);
        }

        $timestampColumn = $action === 'open' ? 'betting_opened_at' : 'betting_locked_at';
        $stmt = $pdo->prepare("UPDATE matches SET status = ?, {$timestampColumn} = NOW() WHERE match_id = ?");
        $stmt->execute([$nextStatus, $matchId]);
        writeAdminLog(
            $pdo,
            $matchId,
            $adminId,
            $action,
            $action === 'open' ? 'Betting opened' : 'Betting locked'
        );
        $pdo->commit();

        triggerLiveRefresh(3000);
        respondWithAdminMatch($pdo, $action === 'open' ? 'Betting opened' : 'Betting locked');
    }

    if ($action === 'payout') {
        $winner = $input['winner'] ?? '';

        if (!in_array($winner, ['ChadGPT', 'GROKOZILLA'], true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid winner'], 400);
        }

        $pdo->beginTransaction();
        $match = fetchLockedMatch($pdo, $matchId);

        if (!$match) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Match not found'], 404);
        }

        if (!in_array($match['status'], ['betting_locked', 'finished'], true)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Lock the match before payout'], 400);
        }

        $winnerPool = $winner === 'ChadGPT'
            ? (int)$match['chadgpt_pool']
            : (int)$match['grokozilla_pool'];
        $totalPool = (int)$match['total_pool'];
        $finalMultiplier = $winnerPool > 0 ? round($totalPool / $winnerPool, 2) : 0;

        if ($winnerPool <= 0) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Winner side has no bets. Cancel and refund instead.'], 400);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM bets
            WHERE match_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$matchId]);
        $bets = $stmt->fetchAll();

        foreach ($bets as $bet) {
            $isWon = $bet['selected_robot'] === $winner;
            $payoutAmount = $isWon ? (int)floor((int)$bet['amount'] * $finalMultiplier) : 0;

            if ($isWon && $payoutAmount > 0) {
                $stmt = $pdo->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE user_id = ?");
                $stmt->execute([$payoutAmount, (int)$bet['user_id']]);
            }

            $stmt = $pdo->prepare("
                UPDATE bets
                SET final_multiplier = ?, is_won = ?, payout_amount = ?, refunded = 0
                WHERE bet_id = ?
            ");
            $stmt->execute([
                $finalMultiplier,
                $isWon ? 1 : 0,
                $payoutAmount,
                (int)$bet['bet_id']
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE matches
            SET status = 'paid', winner = ?, finished_at = COALESCE(finished_at, NOW()), paid_at = NOW()
            WHERE match_id = ?
        ");
        $stmt->execute([$winner, $matchId]);
        writeAdminLog($pdo, $matchId, $adminId, 'payout', 'Winner: ' . $winner);
        $pdo->commit();

        triggerLiveRefresh(3000);
        respondWithAdminMatch($pdo, 'Payout completed');
    }

    if ($action === 'cancel') {
        $pdo->beginTransaction();
        $match = fetchLockedMatch($pdo, $matchId);

        if (!$match) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Match not found'], 404);
        }

        if (in_array($match['status'], ['paid', 'cancelled'], true)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Match is already closed'], 400);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM bets
            WHERE match_id = ? AND refunded = 0 AND payout_amount IS NULL
            FOR UPDATE
        ");
        $stmt->execute([$matchId]);
        $bets = $stmt->fetchAll();

        foreach ($bets as $bet) {
            $stmt = $pdo->prepare("UPDATE users SET coin_balance = coin_balance + ? WHERE user_id = ?");
            $stmt->execute([(int)$bet['amount'], (int)$bet['user_id']]);

            $stmt = $pdo->prepare("
                UPDATE bets
                SET is_won = NULL, payout_amount = NULL, final_multiplier = NULL, refunded = 1
                WHERE bet_id = ?
            ");
            $stmt->execute([(int)$bet['bet_id']]);
        }

        $stmt = $pdo->prepare("
            UPDATE matches
            SET status = 'cancelled', winner = NULL, cancelled_at = NOW()
            WHERE match_id = ?
        ");
        $stmt->execute([$matchId]);
        writeAdminLog($pdo, $matchId, $adminId, 'cancel', 'Match cancelled and unpaid bets refunded');
        $pdo->commit();

        triggerLiveRefresh(3000);
        respondWithAdminMatch($pdo, 'Match cancelled and bets refunded');
    }

    jsonResponse([
        'success' => false,
        'message' => 'Unknown action'
    ], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Admin match action failed',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}
