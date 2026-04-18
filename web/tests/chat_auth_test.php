<?php
/**
 * PTMD Tests — Chat Auth (permissions matrix)
 */

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions = $ptmdAssertions ?? 0;

if (!function_exists('chat_assert')) {
    function chat_assert(bool $cond, string $label): void
    {
        global $ptmdTestFailures, $ptmdAssertions;
        $ptmdAssertions++;
        if (!$cond) {
            $ptmdTestFailures[] = "[chat_auth] FAIL: {$label}";
        }
    }
}

if (!function_exists('get_db')) {
    function get_db(): ?PDO
    {
        return null;
    }
}

require_once __DIR__ . '/../inc/chat_auth.php';

if (!function_exists('chat_test_make_user')) {
    function chat_test_make_user(string $role, string $status = 'active'): array
    {
        return ['id' => 1, 'role' => $role, 'status' => $status, 'display_name' => 'Test', 'username' => 'test'];
    }
}

// ── Role rank ordering ────────────────────────────────────────────────────────
chat_assert(chat_role_rank('guest') === 1, 'guest rank is 1');
chat_assert(chat_role_rank('registered') === 2, 'registered rank is 2');
chat_assert(chat_role_rank('moderator') === 3, 'moderator rank is 3');
chat_assert(chat_role_rank('admin') === 4, 'admin rank is 4');
chat_assert(chat_role_rank('super_admin') === 5, 'super_admin rank is 5');
chat_assert(chat_role_rank('unknown') === 0, 'unknown role rank is 0');

// ── Anonymous caller default behavior ─────────────────────────────────────────
chat_assert(chat_can('send', null), 'anonymous caller can send');
chat_assert(!chat_can('react', null), 'anonymous caller cannot react');
chat_assert(!chat_can('unknown_action', null), 'unknown action is denied');

// ── Guest permissions ─────────────────────────────────────────────────────────
$guest = chat_test_make_user('guest');
chat_assert(chat_can('send', $guest), 'guest can send');
chat_assert(!chat_can('react', $guest), 'guest cannot react');
chat_assert(!chat_can('pin', $guest), 'guest cannot pin');
chat_assert(!chat_can('hide', $guest), 'guest cannot hide');
chat_assert(!chat_can('delete', $guest), 'guest cannot delete');

// ── Registered permissions ────────────────────────────────────────────────────
$registered = chat_test_make_user('registered');
chat_assert(chat_can('send', $registered), 'registered can send');
chat_assert(chat_can('react', $registered), 'registered can react');
chat_assert(chat_can('reply', $registered), 'registered can reply');
chat_assert(chat_can('highlight', $registered), 'registered can highlight');
chat_assert(!chat_can('pin', $registered), 'registered cannot pin');
chat_assert(!chat_can('hide', $registered), 'registered cannot hide');

// ── Moderator permissions ─────────────────────────────────────────────────────
$moderator = chat_test_make_user('moderator');
$registeredTarget = chat_test_make_user('registered');
$moderatorTarget = chat_test_make_user('moderator');

chat_assert(chat_can('pin', $moderator), 'moderator can pin');
chat_assert(chat_can('hide', $moderator, $registeredTarget), 'moderator can hide registered');
chat_assert(chat_can('unhide', $moderator), 'moderator can unhide');
chat_assert(chat_can('delete', $moderator, $registeredTarget), 'moderator can delete registered');
chat_assert(chat_can('restore', $moderator), 'moderator can restore');
chat_assert(chat_can('mute_user', $moderator, $registeredTarget), 'moderator can mute registered');
chat_assert(chat_can('ban_user', $moderator, $registeredTarget), 'moderator can ban registered');
chat_assert(chat_can('add_strike', $moderator, $registeredTarget), 'moderator can add strike to registered');
chat_assert(chat_can('view_audit', $moderator), 'moderator can view audit');
chat_assert(chat_can('start_trivia', $moderator), 'moderator can start trivia');
chat_assert(chat_can('close_trivia', $moderator), 'moderator can close trivia');
chat_assert(!chat_can('hide', $moderator, $moderatorTarget), 'moderator cannot hide peer moderator');
chat_assert(!chat_can('mute_user', $moderator, $moderatorTarget), 'moderator cannot mute peer moderator');
chat_assert(!chat_can('manage_roles', $moderator), 'moderator cannot manage roles');

// ── Admin permissions ─────────────────────────────────────────────────────────
$admin = chat_test_make_user('admin');
$moderatorTarget2 = chat_test_make_user('moderator');
$adminTarget = chat_test_make_user('admin');

chat_assert(chat_can('manage_roles', $admin), 'admin can manage roles');
chat_assert(chat_can('manage_rooms', $admin), 'admin can manage rooms');
chat_assert(chat_can('hide', $admin, $moderatorTarget2), 'admin can hide moderator');
chat_assert(chat_can('mute_user', $admin, $moderatorTarget2), 'admin can mute moderator');
chat_assert(!chat_can('hide', $admin, $adminTarget), 'admin cannot hide peer admin');

// ── Super admin permissions ───────────────────────────────────────────────────
$superAdmin = chat_test_make_user('super_admin');
chat_assert(chat_can('hide', $superAdmin, chat_test_make_user('admin')), 'super_admin can hide admin');
chat_assert(chat_can('manage_rooms', $superAdmin), 'super_admin can manage rooms');

// ── Banned user cannot do anything ────────────────────────────────────────────
$banned = chat_test_make_user('admin', 'banned');
chat_assert(!chat_can('send', $banned), 'banned admin cannot send');
chat_assert(!chat_can('pin', $banned), 'banned admin cannot pin');
chat_assert(!chat_can('manage_roles', $banned), 'banned admin cannot manage roles');

// ── Current-user role checks with no active user ──────────────────────────────
$_SESSION['chat_user_id'] = null;
chat_assert(!chat_user_has_role('registered'), 'chat_user_has_role returns false with no current user');
chat_assert(!is_chat_moderator(), 'is_chat_moderator returns false with no current user');
chat_assert(chat_user_send_guard() === null, 'chat_user_send_guard allows anonymous pass-through');

// ── Permissions matrix structure ──────────────────────────────────────────────
$matrix = chat_permissions_matrix();
chat_assert(is_array($matrix), 'permissions matrix returns array');
chat_assert(isset($matrix['guest']), 'permissions matrix has guest role');
chat_assert(isset($matrix['super_admin']['manage_rooms']), 'permissions matrix includes super_admin.manage_rooms');
chat_assert($matrix['super_admin']['manage_rooms'] === true, 'super_admin can manage_rooms in matrix');
chat_assert($matrix['guest']['react'] === false, 'guest cannot react in matrix');
chat_assert($matrix['moderator']['ban_user'] === true, 'moderator can ban users in matrix');
