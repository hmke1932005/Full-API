<?php

/**
 * منقولة من config/messaging.php القديمة — نفس القيم الافتراضية بالظبط
 * (بند 18: Messaging). مفيش UI إداري لتعديلها لسه (ده جزء من بند 25 —
 * Security portal / Admin policies)، فالقيم هنا ثابتة زي ما كانت قبل أي
 * override إداري.
 */
return [
    'max_message_length' => 8000,
    'max_attachments_per_message' => 10,

    'rate_limit' => [
        'max_messages'    => 30,
        'window_seconds'  => 60,
    ],

    'edit_window_minutes' => 0, // 0 = بدون حد زمني للتعديل
    'delete_for_everyone_window_minutes' => 15,
    'undo_send_window_seconds' => 10,

    'presence' => [
        'online_threshold_s' => 30,
        'typing_ttl_s'       => 6,
    ],

    'poll_interval_ms' => 4000,
];
