<?php
/** GET ?id= : photo d’un signalement VALIDÉ uniquement. */
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

$id = (string)($_GET['id'] ?? '');
$r = sg_valid_id($id) ? sg_load_report($id) : null;
$path = ($r && ($r['moderation'] ?? '') === 'validated') ? sg_photo_path($r) : null;

if (!$path || !is_file($path)) {
    http_response_code(404);
    sg_common_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Photo introuvable.';
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
sg_common_headers();
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=600');
header('Content-Disposition: inline; filename="signalement-' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
