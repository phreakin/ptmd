<?php
/**
 * PTMD Tests — Chat Auth (permissions matrix)
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function chat_assert(bool $cond, string $label): void
{
    global $ptmdTestFailures, $ptmdAssertions;
    $ptmdAssertions++;
    if (!$cond) {
        $ptmdTestFailures[] = "[chat_auth] FAIL: {$label}";
    }
}

function makeUser(string $role, string $status = 'active'): array
{
    return ['id' => 1, 'role' => $role, 'status' => $status, 'display_name' => 'Test', 'username' => 'test'];
}

// ── Role rank ordering ────────────────────────────────────────────────────────
chat_assert(chat_role_rank('guest')       === 1, 'guest rank is 1');
chat_assert(chat_role_rank('registered')  === 2, 'registered rank is 2');
chat_assert(chat_role_rank('moderator')   === 3, 'moderator rank is 3');
chat_assert(chat_role_rank('admin')       === 4, 'admin rank is 4');
chat_assert(chat_role_rank('super_admin') === 5, 'super_admin rank is 5');
chat_assert(chat_role_rank('unknown')     === 0, 'unknown role rank is 0');

// ── Guest permissions ─────────────────────────────────────────────────────────
$guest = makeUser('guest');
chat_assert( chat_can('send',    $guest), 'guest can send');
chat_assert(!chat_can('react',   $guest), 'guest cannot react');
chat_assert(!chat_can('pin',     $guest), 'guest cannot pin');
chat_assert(!chat_can('hide',    $guest), 'guest cannot hide');
chat_assert(!chat_can('delete',  $guest), 'guest cannot delete');

// ── Registered permissions ────────────────────────────────────────────────────
$reg = makeUser('registered');
chat_assert(chat_can('send',       $reg), 'registered can send');
chat_assert(chat_can('react',      $reg), 'registered can react');
chat_assert(chat_can('reply',      $reg), 'registered can reply');
chat_assert(chat_can('highlight',  $reg), 'registered can highlight');
chat_assert(!chat_can('pin',       $reg), 'registered cannot pin');
chat_assert(!chat_can('hide',      $reg), 'registered cannot hide');

// ── Moderator permissions ─────────────────────────────────────────────────────
$mod     = makeUser('moderator');
$regTgt  = makeUser('registered');
$modTgt  = makeUser('moderator');

chat_assert(chat_can('pin',      $mod),               'mod can pin');
chat_assert(chat_can('hide',     $mod, $regTgt),      'mod can hide registered user msg');
chat_assert(chat_can('delete',   $mod, $regTgt),      'mod can delete registered user msg');
chat_assert(chat_can('mute_user',$mod, $regTgt),      'mod can mute registered user');
chat_assert(!chat_can('hide',    $mod, $modTgt),      'mod cannot hide peer mod message');
chat_assert(!chat_can('mute_user',$mod, $modTgt),     'mod cannot mute peer mod');
chat_assert(!chat_can('manage_roles', $mod),          'mod cannot manage roles');

// ── Admin permissions ─────────────────────────────────────────────────────────
$admin   = makeUser('admin');
$modTgt2 = makeUser('moderator');
chat_assert(chat_can('manage_roles', $admin),         'admin can manage roles');
chat_assert(chat_can('hide',   $admin, $modTgt2),     'admin can hide mod message');
chat_assert(chat_can('mute_user', $admin, $modTgt2),  'admin can mute mod');

$adminTgt = makeUser('admin');
chat_assert(!chat_can('hide', $admin, $adminTgt),     'admin cannot hide peer admin message');

// ── Super admin permissions ───────────────────────────────────────────────────
$su = makeUser('super_admin');
chat_assert(chat_can('hide', $su, makeUser('admin')),     'super_admin can hide admin');
chat_assert(chat_can('manage_rooms', $su),                'super_admin can manage rooms');

// ── Banned user cannot do anything ───────────────────────────────────────────
$banned = makeUser('admin', 'banned');
chat_assert(!chat_can('send',    $banned), 'banned admin cannot send');
chat_assert(!chat_can('pin',     $banned), 'banned admin cannot pin');

// ── Permissions matrix structure ─────────────────────────────────────────────
$matrix = chat_permissions_matrix();
chat_assert(is_array($matrix),                             'permissions_matrix returns array');
chat_assert(isset($matrix['guest']),                       'matrix has guest key');
chat_assert(isset($matrix['super_admin']['manage_rooms']),'matrix has super_admin.manage_rooms');
chat_assert($matrix['super_admin']['manage_rooms'] === true, 'super_admin can manage_rooms in matrix');
chat_assert($matrix['guest']['react'] === false,           'guest cannot react in matrix');
