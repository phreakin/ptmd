<?php
/**
 * PTMD API — Chat Emojis
 *
 * GET → returns categorized emoji list for the chat emoji picker.
 *       Public endpoint.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

$categories = [
    'Reactions' => ['😀','😂','😭','😤','😱','🤔','🥲','😎','🤦','😅','🙄','😬','🤯','😴','🥳'],
    'Vibes'     => ['🔥','💯','❤️','✅','⚠️','💀','👀','🎉','👏','🙏','💡','⚡','🌊','🎯','💥'],
    'Case Work' => ['📄','🔍','⚖️','🔒','📰','🕵️','🗂️','✍️','📋','📊','🖊️','🔎','📌','🚨','🏛️'],
    'People'    => ['👋','🤝','💪','👁️','🫡','🤫','🤐','👎','👍','🫶','🫠','🫣','🫤','🤷','💁'],
    'Objects'   => ['💻','📱','🎙️','🧾','💸','🏦','📜','🗳️','⚙️','🔑','🗝️','💣','🔗','📡','🧪'],
    'Wild'      => ['🌶️','🍿','🎭','🦊','🐍','🦈','🎲','🃏','🎬','🎤','🌐','🔮','🃏','🎰','🧿'],
];

echo json_encode(['ok' => true, 'categories' => $categories]);
