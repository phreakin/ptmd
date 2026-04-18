<?php
/**
 * PTMD API — Chat Logout
 *
 * Clears the chat user session and remember-me cookie, then redirects.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

chat_logout();
redirect(route_chat(), 'You have been signed out.', 'info');
