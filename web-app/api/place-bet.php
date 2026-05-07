<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/live-refresh.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse([
            'success' => false,
            'message' => 'Only POST method is allowed'
        ], 405);
    }

    $userId = requireUser();

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid JSON body'
        ], 400);
    }

    $selectedRobot = $input['selectedRobot'] ?? '';
    $amount = (int)($input['amount'] ?? 0);

    if (!in_array($selectedRobot, ['ChadGPT', 'GROKOZILLA'], true)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid robot selection'
        ], 400);
    }

    if ($amount <= 0) {
        jsonResponse([
            'success' => false,
            'message' => 'Bet amount must be greater than zero'
        ], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->query("
        SELECT *
        FROM matches
        WHERE status = 'betting_open'
        ORDER BY match_id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $match = $stmt->fetch();

    if (!$match) {
        $pdo->rollBack();

        jsonResponse([
            'success' => false,
            'message' => 'There is no active betting match'
        ], 400);
    }

    $matchId = (int)$match['match_id'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE user_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $pdo->rollBack();

        jsonResponse([
            'success' => false,
            'message' => 'User not found'
        ], 404);
    }

    if ((int)$user['coin_balance'] < $amount) {
        $pdo->rollBack();

        jsonResponse([
            'success' => false,
            'message' => 'Not enough coins'
        ], 400);
    }

    $stmt = $pdo->prepare("
        SELECT bet_id
        FROM bets
        WHERE match_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$matchId, $userId]);
    $existingBet = $stmt->fetch();

    if ($existingBet) {
        $pdo->rollBack();

        jsonResponse([
            'success' => false,
            'message' => 'You already placed a bet for this match'
        ], 400);
    }

    $totalPoolAfter = (int)$match['total_pool'] + $amount;

    if ($selectedRobot === 'ChadGPT') {
        $selectedPoolAfter = (int)$match['chadgpt_pool'] + $amount;
    } else {
        $selectedPoolAfter = (int)$match['grokozilla_pool'] + $amount;
    }

    $multiplierAtBet = $selectedPoolAfter > 0
        ? round($totalPoolAfter / $selectedPoolAfter, 2)
        : 1.00;

    $stmt = $pdo->prepare("
        UPDATE users
        SET coin_balance = coin_balance - ?
        WHERE user_id = ?
    ");
    $stmt->execute([$amount, $userId]);

    $stmt = $pdo->prepare("
        INSERT INTO bets (
            match_id,
            user_id,
            selected_robot,
            amount,
            multiplier_at_bet
        )
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $matchId,
        $userId,
        $selectedRobot,
        $amount,
        $multiplierAtBet
    ]);

    if ($selectedRobot === 'ChadGPT') {
        $stmt = $pdo->prepare("
            UPDATE matches
            SET
                total_pool = total_pool + ?,
                chadgpt_pool = chadgpt_pool + ?,
                total_bets_count = total_bets_count + 1,
                chadgpt_bets_count = chadgpt_bets_count + 1
            WHERE match_id = ?
        ");
        $stmt->execute([$amount, $amount, $matchId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE matches
            SET
                total_pool = total_pool + ?,
                grokozilla_pool = grokozilla_pool + ?,
                total_bets_count = total_bets_count + 1,
                grokozilla_bets_count = grokozilla_bets_count + 1
            WHERE match_id = ?
        ");
        $stmt->execute([$amount, $amount, $matchId]);
    }

    $stmt = $pdo->prepare("
        SELECT user_id, username, coin_balance
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $updatedUser = $stmt->fetch();

    $pdo->commit();

    $refreshState = triggerLiveRefresh(3000);

    jsonResponse([
        'success' => true,
        'message' => 'Bet placed successfully',
        'bet' => [
            'selectedRobot' => $selectedRobot,
            'amount' => $amount,
            'multiplierAtBet' => $multiplierAtBet
        ],
        'user' => [
            'userId' => (int)$updatedUser['user_id'],
            'username' => $updatedUser['username'],
            'coinBalance' => (int)$updatedUser['coin_balance']
        ],
        'refresh' => $refreshState
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to place bet',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}
