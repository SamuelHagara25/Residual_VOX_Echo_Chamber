<?php
// Simple JSON file-backed API for shared notes
// Methods:
//  - GET  notes.php?action=list        → returns JSON array of notes
//  - POST notes.php {text,title,author,when} → appends and returns updated array

header('Content-Type: application/json; charset=utf-8');

$file = __DIR__ . DIRECTORY_SEPARATOR . 'notes.json';
$maxItems = 500;         // keep at most 500 notes
$maxTextLen = 2000;      // max chars for text
$maxTitleLen = 200;      // max chars for title
$maxAuthorLen = 120;     // max chars for author

function read_notes($file) {
    if (!file_exists($file)) {
        return [];
    }
    $data = @file_get_contents($file);
    if ($data === false || $data === '') return [];
    $json = json_decode($data, true);
    return is_array($json) ? $json : [];
}

function write_notes($file, $notes) {
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok !== false;
}

function sanitize($s, $max) {
    $s = is_string($s) ? $s : '';
    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') > $max) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    }
    // keep plain text only; frontend renders as textContent
    $s = strip_tags($s);
    return $s;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'list') {
        echo json_encode(read_notes($file), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
    exit;
}

if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

    $text = sanitize($data['text'] ?? '', $maxTextLen);
    if ($text === '') { http_response_code(422); echo json_encode(['error' => 'Text required']); exit; }
    $title = sanitize($data['title'] ?? '', $maxTitleLen);
    $author = sanitize($data['author'] ?? '', $maxAuthorLen);
    $when = sanitize($data['when'] ?? '', 64);

    $notes = read_notes($file);
    array_unshift($notes, [
        'text' => $text,
        'title' => $title,
        'author' => $author,
        'when' => $when,
    ]);
    if (count($notes) > $maxItems) {
        $notes = array_slice($notes, 0, $maxItems);
    }
    if (!write_notes($file, $notes)) {
        http_response_code(500);
        echo json_encode(['error' => 'Write failed']);
        exit;
    }
    echo json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;

