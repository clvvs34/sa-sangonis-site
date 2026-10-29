<?php
/**
 * POST : crée un signalement (en attente de validation par le groupe).
 * GET ?jeton=1 : délivre un jeton horodaté pour le formulaire.
 */
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['jeton'])) {
    sg_json(['ok' => true, 'jeton' => sg_form_token()]);
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    sg_json(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
}

// Requête trop volumineuse : PHP vide alors $_POST et $_FILES
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    sg_json(['ok' => false, 'message' => 'Envoi trop volumineux : choisissez une photo plus légère.'], 413);
}
if (!sg_same_origin()) {
    sg_json(['ok' => false, 'message' => 'Requête refusée.'], 403);
}

// Pot de miel : un champ invisible que seuls les robots remplissent.
if (trim((string)($_POST['site_web'] ?? '')) !== '') {
    sg_json(['ok' => true, 'message' => 'Merci, votre signalement a bien été reçu.']); // réponse neutre
}
if ($err = sg_check_form_token((string)($_POST['jeton'] ?? ''))) {
    sg_json(['ok' => false, 'message' => $err], 400);
}

// --- Validation des champs -------------------------------------------------
$errors = [];

$category = (string)($_POST['categorie'] ?? '');
if (!isset(SG_CATEGORIES[$category])) $errors[] = 'Choisissez une catégorie.';

$latRaw = (string)($_POST['lat'] ?? ''); $lngRaw = (string)($_POST['lng'] ?? '');
$num = '/^-?\d{1,3}(\.\d{1,12})?$/';
if (!preg_match($num, $latRaw) || !preg_match($num, $lngRaw)) {
    $errors[] = 'Indiquez l’emplacement du problème sur la carte.';
    $lat = $lng = 0.0;
} else {
    $lat = round((float)$latRaw, 6); $lng = round((float)$lngRaw, 6);
    if (!sg_in_commune($lat, $lng)) $errors[] = 'Cet emplacement est en dehors de Saint-André-de-Sangonis.';
}

$comment = (string)($_POST['commentaire'] ?? '');
if (!mb_check_encoding($comment, 'UTF-8')) $comment = '';
$comment = trim(preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $comment)) ?? '');
if (mb_strlen($comment) > 1000) $errors[] = 'Le commentaire est limité à 1000 caractères.';

$email = trim((string)($_POST['email'] ?? ''));
if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    $errors[] = 'L’adresse e-mail n’est pas valide.';
}

$file = $_FILES['photo'] ?? null;
$hasPhoto = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasPhoto) {
    $e = (int)$file['error'];
    if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) $errors[] = 'Photo trop lourde (8 Mo maximum).';
    elseif ($e !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) $errors[] = 'La photo n’a pas pu être reçue.';
    elseif ((int)$file['size'] > SG_MAX_UPLOAD) $errors[] = 'Photo trop lourde (8 Mo maximum).';
}
if (!$hasPhoto && $comment === '') $errors[] = 'Ajoutez une photo ou un commentaire (ou les deux).';

if ($errors) sg_json(['ok' => false, 'message' => implode(' ', $errors)], 422);

// --- Limites ------------------------------------------------------------------
if (sg_rate_hit('create', SG_RATE_CREATE, 3600, false)) {
    sg_json(['ok' => false, 'message' => 'Vous avez déjà envoyé plusieurs signalements récemment. Merci de réessayer dans une heure.'], 429);
}
$all = sg_all_reports();
$pending = count(array_filter($all, fn($r) => ($r['moderation'] ?? '') === 'pending'));
if (count($all) >= SG_MAX_REPORTS || $pending >= SG_MAX_PENDING) {
    sg_json(['ok' => false, 'message' => 'Le service reçoit trop de signalements en ce moment. Réessayez plus tard ou écrivez-nous à contact@sa-sangonis.fr.'], 503);
}

$photoData = null; $photoExt = null;
if ($hasPhoto) {
    if (sg_storage_used() + (int)$file['size'] > SG_STORAGE_CAP) {
        sg_json(['ok' => false, 'message' => 'L’espace réservé aux photos est plein pour le moment. Envoyez votre signalement sans photo, avec un commentaire.'], 507);
    }
    [$photoData, $photoExt, $imgErr] = sg_process_image((string)$file['tmp_name']);
    if ($imgErr) sg_json(['ok' => false, 'message' => $imgErr], 422);
    if (sg_storage_used() + strlen($photoData) > SG_STORAGE_CAP) {
        sg_json(['ok' => false, 'message' => 'L’espace réservé aux photos est plein pour le moment. Envoyez votre signalement sans photo, avec un commentaire.'], 507);
    }
}

// --- Enregistrement -------------------------------------------------------------
if (sg_rate_hit('create', SG_RATE_CREATE, 3600, true)) {
    sg_json(['ok' => false, 'message' => 'Vous avez déjà envoyé plusieurs signalements récemment. Merci de réessayer dans une heure.'], 429);
}
$id = bin2hex(random_bytes(8));
$report = [
    'id'          => $id,
    'created'     => gmdate('c'),
    'category'    => $category,
    'lat'         => $lat,
    'lng'         => $lng,
    'comment'     => $comment,
    'email'       => $email,
    'photo'       => null,
    'moderation'  => 'pending',
    'status'      => 'signale',
    'validated_at'=> null,
    'resolved_at' => null,
];
if ($photoData !== null) {
    $name = $id . '.' . $photoExt;
    if (!sg_write_atomic(sg_data_dir() . '/photos/' . $name, $photoData)) {
        sg_json(['ok' => false, 'message' => 'La photo n’a pas pu être enregistrée. Réessayez.'], 500);
    }
    $report['photo'] = $name;
}
if (!sg_save_report($report)) {
    if ($report['photo']) @unlink(sg_data_dir() . '/photos/' . $report['photo']);
    sg_json(['ok' => false, 'message' => 'Le signalement n’a pas pu être enregistré. Réessayez.'], 500);
}

sg_json([
    'ok' => true,
    'message' => 'Merci, votre signalement a bien été reçu. Il sera vérifié par les élus du groupe avant d’apparaître sur la carte.',
], 201);
