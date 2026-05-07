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

    $userPayload = [
        'userId' => (int)$user['user_id'],
        'username' => $user['username'],
        'coinBalance' => (int)$user['coin_balance']
    ];

    $stmt = $pdo->query("
        SELECT *
        FROM matches
        WHERE status IN ('created', 'betting_open', 'betting_locked', 'finished', 'paid', 'cancelled')
        ORDER BY match_id DESC
        LIMIT 1
    ");
    $match = $stmt->fetch();

    if (!$match) {
        jsonResponse([
            'success' => true,
            'user' => $userPayload,
            'match' => null,
            'userBet' => null,
            'bets' => []
        ]);
    }

    $matchId = (int)$match['match_id'];

    $totalPool = (int)$match['total_pool'];
    $chadPool = (int)$match['chadgpt_pool'];
    $grokoPool = (int)$match['grokozilla_pool'];

    $chadMultiplier = $chadPool > 0 ? round($totalPool / $chadPool, 2) : 0;
    $grokoMultiplier = $grokoPool > 0 ? round($totalPool / $grokoPool, 2) : 0;

    $stmt = $pdo->prepare("
        SELECT selected_robot, COUNT(*) AS user_count, COALESCE(SUM(amount), 0) AS coin_sum
        FROM bets
        WHERE match_id = ?
        GROUP BY selected_robot
    ");
    $stmt->execute([$matchId]);
    $rows = $stmt->fetchAll();

    $breakdown = [
        'ChadGPT' => [
            'users' => 0,
            'coins' => 0,
            'multiplier' => $chadMultiplier
        ],
        'GROKOZILLA' => [
            'users' => 0,
            'coins' => 0,
            'multiplier' => $grokoMultiplier
        ]
    ];

    foreach ($rows as $row) {
        $robot = $row['selected_robot'];

        if (isset($breakdown[$robot])) {
            $breakdown[$robot]['users'] = (int)$row['user_count'];
            $breakdown[$robot]['coins'] = (int)$row['coin_sum'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT b.*, u.username
        FROM bets b
        INNER JOIN users u ON u.user_id = b.user_id
        WHERE b.match_id = ?
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$matchId]);
    $bets = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT *
        FROM bets
        WHERE match_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$matchId, $userId]);
    $userBet = $stmt->fetch();

    jsonResponse([
        'success' => true,

        'user' => $userPayload,

        'match' => [
            'matchId' => $matchId,
            'title' => $match['title'],
            'status' => $match['status'],
            'winner' => $match['winner'],
            'totalPool' => $totalPool,
            'chadgptPool' => $chadPool,
            'grokozillaPool' => $grokoPool,
            'totalBetsCount' => (int)$match['total_bets_count'],
            'chadgptBetsCount' => (int)$match['chadgpt_bets_count'],
            'grokozillaBetsCount' => (int)$match['grokozilla_bets_count'],
            'multipliers' => [
                'ChadGPT' => $chadMultiplier,
                'GROKOZILLA' => $grokoMultiplier
            ],
            'breakdown' => $breakdown
        ],

        'userBet' => $userBet ? [
            'selectedRobot' => $userBet['selected_robot'],
            'amount' => (int)$userBet['amount'],
            'multiplierAtBet' => (float)$userBet['multiplier_at_bet'],
            'finalMultiplier' => $userBet['final_multiplier'] !== null ? (float)$userBet['final_multiplier'] : null,
            'isWon' => $userBet['is_won'] !== null ? (bool)$userBet['is_won'] : null,
            'payoutAmount' => $userBet['payout_amount'] !== null ? (int)$userBet['payout_amount'] : null,
            'refunded' => (bool)$userBet['refunded']
        ] : null,

        'bets' => array_map(function ($bet) {
            return [
                'username' => $bet['username'],
                'selectedRobot' => $bet['selected_robot'],
                'amount' => (int)$bet['amount'],
                'createdAt' => $bet['created_at']
            ];
        }, $bets)
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to get live stats',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}