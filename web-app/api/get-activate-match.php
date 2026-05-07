<?php
require_once __DIR__ . '/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse([
            'success' => false,
            'message' => 'Only GET method is allowed'
        ], 405);
    }

    $userId = requireUser();

    $stmt = $pdo->prepare("
        SELECT user_id, username, coin_balance
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse([
            'success' => false,
            'message' => 'User not found'
        ], 404);
    }

    $stmt = $pdo->query("
        SELECT *
        FROM matches
        WHERE status IN ('created', 'betting_open', 'betting_locked', 'finished')
        ORDER BY match_id DESC
        LIMIT 1
    ");
    $match = $stmt->fetch();

    if (!$match) {
        jsonResponse([
            'success' => true,
            'user' => [
                'userId' => (int)$user['user_id'],
                'username' => $user['username'],
                'coinBalance' => (int)$user['coin_balance']
            ],
            'match' => null,
            'userBet' => null
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM bets
        WHERE match_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$match['match_id'], $userId]);
    $userBet = $stmt->fetch();

    jsonResponse([
        'success' => true,
        'user' => [
            'userId' => (int)$user['user_id'],
            'username' => $user['username'],
            'coinBalance' => (int)$user['coin_balance']
        ],
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
        'userBet' => $userBet ? [
            'betId' => (int)$userBet['bet_id'],
            'selectedRobot' => $userBet['selected_robot'],
            'amount' => (int)$userBet['amount'],
            'multiplierAtBet' => (float)$userBet['multiplier_at_bet'],
            'finalMultiplier' => $userBet['final_multiplier'] !== null ? (float)$userBet['final_multiplier'] : null,
            'isWon' => $userBet['is_won'] !== null ? (bool)$userBet['is_won'] : null,
            'payoutAmount' => $userBet['payout_amount'] !== null ? (int)$userBet['payout_amount'] : null,
            'refunded' => (bool)$userBet['refunded']
        ] : null
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to get active match',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}