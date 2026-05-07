<?php

function liveRefreshStateFile() {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'robot-battle-live-refresh-' . md5(__DIR__) . '.json';
}

function defaultLiveRefreshState() {
    return [
        'eventId' => 0,
        'triggeredAtMs' => 0,
        'dueAtMs' => 0
    ];
}

function readLiveRefreshState() {
    $file = liveRefreshStateFile();

    if (!is_file($file)) {
        return defaultLiveRefreshState();
    }

    $contents = file_get_contents($file);
    $state = json_decode($contents ?: '', true);

    if (!is_array($state)) {
        return defaultLiveRefreshState();
    }

    return [
        'eventId' => (int)($state['eventId'] ?? 0),
        'triggeredAtMs' => (int)($state['triggeredAtMs'] ?? 0),
        'dueAtMs' => (int)($state['dueAtMs'] ?? 0)
    ];
}

function triggerLiveRefresh($delayMs = 3000) {
    $file = liveRefreshStateFile();
    $nowMs = (int)floor(microtime(true) * 1000);

    $handle = fopen($file, 'c+');

    if (!$handle) {
        return readLiveRefreshState();
    }

    flock($handle, LOCK_EX);

    $contents = stream_get_contents($handle);
    $current = json_decode($contents ?: '', true);

    if (!is_array($current)) {
        $current = defaultLiveRefreshState();
    }

    $state = [
        'eventId' => (int)($current['eventId'] ?? 0) + 1,
        'triggeredAtMs' => $nowMs,
        'dueAtMs' => $nowMs + $delayMs
    ];

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $state;
}
