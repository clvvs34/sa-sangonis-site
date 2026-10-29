<?php
/** GET : liste publique des signalements VALIDÉS par le groupe. */
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    sg_json(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$list = [];
foreach (sg_all_reports() as $r) {
    if (($r['moderation'] ?? '') !== 'validated') continue;
    $list[] = sg_public_view($r, 'api/photo.php?id=');
}
sg_json(['ok' => true, 'signalements' => $list]);
