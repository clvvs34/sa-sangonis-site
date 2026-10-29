<?php
/**
 * Modération des signalements — réservé aux élus du groupe.
 * Mot de passe partagé unique ; son empreinte bcrypt est stockée dans config.php,
 * dans le dossier de données situé hors de la racine web.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/api/inc/lib.php';

const SG_SESSION_IDLE = 7200; // déconnexion après 2 h d’inactivité

/* ---- Session ---------------------------------------------------------------- */
$secure = sg_is_https() || !sg_is_local();
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) SG_SESSION_IDLE);
session_save_path(sg_data_dir() . '/sessions');
session_name('sg_moderation');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/moderation/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (!empty($_SESSION['auth']) && (time() - (int)($_SESSION['last'] ?? 0)) > SG_SESSION_IDLE) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$authed = !empty($_SESSION['auth']);
if ($authed) $_SESSION['last'] = time();

$cfg = sg_config();
$configured = !empty($cfg['password_hash']);

/* ---- En-têtes ------------------------------------------------------------- */
sg_common_headers();
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");

/* ---- Photo d’un signalement (y compris en attente) ----------------------------- */
if (isset($_GET['photo'])) {
    $r = $authed ? sg_load_report((string)$_GET['photo']) : null;
    $p = $r ? sg_photo_path($r) : null;
    if (!$p || !is_file($p)) { http_response_code(404); exit('Photo introuvable.'); }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($p, PATHINFO_EXTENSION)]);
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: private, no-store');
    readfile($p);
    exit;
}

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$type, $msg]; }
function back(string $query = ''): void { header('Location: ./' . $query, true, 303); exit; }
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---- Actions (POST) --------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'], $token) || !sg_same_origin()) {
        flash('La session a expiré. Recommencez.', 'erreur');
        back();
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'setup') {
        if ($configured) { flash('Le mot de passe est déjà défini.', 'erreur'); back(); }
        $p1 = (string)($_POST['mdp'] ?? ''); $p2 = (string)($_POST['mdp2'] ?? '');
        if (mb_strlen($p1) < 12) { flash('Le mot de passe doit contenir au moins 12 caractères.', 'erreur'); back(); }
        if ($p1 !== $p2) { flash('Les deux mots de passe ne correspondent pas.', 'erreur'); back(); }
        $hash = password_hash($p1, PASSWORD_BCRYPT, ['cost' => 11]);
        $ok = sg_update_config(function (array $c) use ($hash) {
            if (!empty($c['password_hash'])) return false; // déjà défini entre-temps
            $c['password_hash'] = $hash;
            $c['password_set_at'] = gmdate('c');
            return $c;
        });
        if (!$ok) { flash('Le mot de passe n’a pas pu être enregistré (déjà défini ?).', 'erreur'); back(); }
        session_regenerate_id(true);
        $_SESSION['auth'] = true; $_SESSION['last'] = time();
        flash('Mot de passe enregistré. Vous êtes connecté.');
        back();
    }

    if ($action === 'login') {
        if (!$configured) back();
        if (sg_rate_count('login', 900) >= SG_RATE_LOGIN) {
            flash('Trop de tentatives. Réessayez dans 15 minutes.', 'erreur'); back();
        }
        if (password_verify((string)($_POST['mdp'] ?? ''), (string)$cfg['password_hash'])) {
            sg_rate_reset('login');
            session_regenerate_id(true);
            $_SESSION['auth'] = true; $_SESSION['last'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            back();
        }
        sg_rate_hit('login', 1000, 900, true);
        usleep(400000);
        flash('Mot de passe incorrect.', 'erreur');
        back();
    }

    if (!$authed) back();

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        setcookie('sg_moderation', '', ['expires' => 1, 'path' => '/moderation/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        back();
    }

    $id = (string)($_POST['id'] ?? '');
    $vue = (($_POST['vue'] ?? '') === 'publies') ? '?vue=publies' : '';
    $r = sg_load_report($id);
    if (!$r) { flash('Signalement introuvable.', 'erreur'); back($vue); }

    switch ($action) {
        case 'valider':
            $r['moderation'] = 'validated';
            $r['validated_at'] = gmdate('c');
            sg_save_report($r);
            flash('Signalement validé : il est maintenant visible sur la carte publique.');
            break;
        case 'refuser':
            sg_delete_report($id);
            flash('Signalement refusé : données et photo supprimées.');
            break;
        case 'supprimer':
            sg_delete_report($id);
            flash('Signalement supprimé définitivement.');
            break;
        case 'statut':
            $s = (string)($_POST['statut'] ?? '');
            if (!isset(SG_STATUTS[$s])) { flash('Statut inconnu.', 'erreur'); break; }
            $r['status'] = $s;
            $r['resolved_at'] = $s === 'resolu' ? ($r['resolved_at'] ?: gmdate('c')) : null;
            sg_save_report($r);
            flash('Statut mis à jour : ' . SG_STATUTS[$s] . '.');
            break;
        default:
            flash('Action inconnue.', 'erreur');
    }
    back($vue);
}

/* ---- Affichage ---------------------------------------------------------------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'];

$vue = ($_GET['vue'] ?? '') === 'publies' ? 'publies' : 'attente';
$reports = [];
$nPending = $nPublished = 0;
if ($authed) {
    sg_purge_old();
    foreach (sg_all_reports() as $r) {
        $isPending = ($r['moderation'] ?? '') === 'pending';
        $isPending ? $nPending++ : $nPublished++;
        if ($isPending === ($vue === 'attente')) $reports[] = $r;
    }
    $used = sg_storage_used();
}

function date_fr(?string $iso): string
{
    if (!$iso) return '';
    $t = new DateTime($iso);
    $t->setTimezone(new DateTimeZone('Europe/Paris'));
    return $t->format('d/m/Y à H\hi');
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Modération des signalements — Sangonis Cœur d’Hérault</title>
<link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="page-moderation">
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="../index.html" aria-label="Retour au site">
      <img class="brand-mark" src="../assets/favicon.svg" alt="" width="42" height="42">
      <span class="brand-text">Sangonis Cœur d’Hérault<small>Modération des signalements</small></span>
    </a>
    <?php if ($authed): ?>
    <form method="post" class="mod-logout">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button class="nav-toggle mod-btn-header" name="action" value="logout">Se déconnecter</button>
    </form>
    <?php endif; ?>
  </div>
</header>

<main id="contenu" class="mod-main">
  <div class="wrap">
  <?php if ($flash): ?>
    <p class="mod-flash mod-flash--<?= h($flash[0]) ?>" role="status"><?= h($flash[1]) ?></p>
  <?php endif; ?>

  <?php if (!$configured): ?>
    <section class="mod-box">
      <h1>Première mise en service</h1>
      <p>Aucun mot de passe n’est encore défini. Choisissez le mot de passe partagé des élus du groupe (12 caractères minimum). Ce formulaire ne s’affiche qu’une seule fois : il disparaît dès que le mot de passe est enregistré.</p>
      <form method="post" class="form-signal">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="setup">
        <label for="mdp">Mot de passe</label>
        <input type="password" id="mdp" name="mdp" minlength="12" required autocomplete="new-password">
        <label for="mdp2">Confirmer le mot de passe</label>
        <input type="password" id="mdp2" name="mdp2" minlength="12" required autocomplete="new-password">
        <button class="btn" type="submit">Enregistrer le mot de passe</button>
      </form>
    </section>

  <?php elseif (!$authed): ?>
    <section class="mod-box">
      <h1>Connexion</h1>
      <p>Espace réservé aux élus du groupe pour vérifier les signalements avant publication.</p>
      <form method="post" class="form-signal">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label for="mdp">Mot de passe</label>
        <input type="password" id="mdp" name="mdp" required autocomplete="current-password" autofocus>
        <button class="btn" type="submit">Se connecter</button>
      </form>
    </section>

  <?php else: ?>
    <h1 class="mod-titre">Signalements</h1>
    <nav class="mod-onglets" aria-label="Filtrer">
      <a href="./" <?= $vue === 'attente' ? 'aria-current="page"' : '' ?>>En attente <span><?= $nPending ?></span></a>
      <a href="./?vue=publies" <?= $vue === 'publies' ? 'aria-current="page"' : '' ?>>Publiés <span><?= $nPublished ?></span></a>
    </nav>
    <p class="mod-info">Stockage utilisé : <?= number_format($used / 1048576, 1, ',', ' ') ?> Mo sur <?= SG_STORAGE_CAP / 1048576 ?> Mo. Les signalements résolus sont supprimés automatiquement 12 mois après leur résolution.</p>

    <?php if (!$reports): ?>
      <p class="mod-vide"><?= $vue === 'attente' ? 'Aucun signalement en attente de vérification.' : 'Aucun signalement publié.' ?></p>
    <?php endif; ?>

    <div class="mod-liste">
    <?php foreach ($reports as $r):
        $lat = number_format((float)$r['lat'], 5, '.', ''); $lng = number_format((float)$r['lng'], 5, '.', '');
        $osm = "https://www.openstreetmap.org/?mlat=$lat&mlon=$lng#map=19/$lat/$lng"; ?>
      <article class="carte mod-carte">
        <?php if (sg_photo_path($r)): ?>
          <a class="mod-photo" href="?photo=<?= h($r['id']) ?>" target="_blank" rel="noopener"><img src="?photo=<?= h($r['id']) ?>" alt="Photo jointe au signalement" loading="lazy"></a>
        <?php else: ?>
          <div class="mod-photo mod-photo--vide">Pas de photo</div>
        <?php endif; ?>
        <div class="mod-corps">
          <p class="mod-meta"><span class="etiquette"><?= h(SG_CATEGORIES[$r['category']] ?? 'Autre') ?></span>
            <span class="statut statut--<?= h($r['status']) ?>"><?= h(SG_STATUTS[$r['status']] ?? '') ?></span></p>
          <p class="mod-date">Reçu le <?= h(date_fr($r['created'])) ?><?php if (!empty($r['validated_at'])): ?> · publié le <?= h(date_fr($r['validated_at'])) ?><?php endif; ?></p>
          <?php if ($r['comment'] !== ''): ?><p class="mod-commentaire"><?= nl2br(h($r['comment'])) ?></p><?php else: ?><p class="mod-commentaire mod-muet">(sans commentaire)</p><?php endif; ?>
          <p class="mod-lien"><a href="<?= h($osm) ?>" target="_blank" rel="noopener">Voir l’emplacement sur OpenStreetMap</a> (<?= $lat ?>, <?= $lng ?>)</p>
          <p class="mod-lien">Contact : <?= $r['email'] !== '' ? '<a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a> <small>(non publié)</small>' : '<span class="mod-muet">aucun</span>' ?></p>

          <div class="mod-actions">
            <?php if (($r['moderation'] ?? '') === 'pending'): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="btn" name="action" value="valider">Valider</button></form>
              <form method="post" data-confirmer="Refuser ce signalement ? Il sera supprimé avec sa photo."><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>">
                <button class="btn btn--secondaire" name="action" value="refuser">Refuser</button></form>
            <?php endif; ?>
            <form method="post" class="mod-statut"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="vue" value="<?= h($vue) ?>"><input type="hidden" name="action" value="statut">
              <label for="statut-<?= h($r['id']) ?>">Statut</label>
              <select id="statut-<?= h($r['id']) ?>" name="statut">
                <?php foreach (SG_STATUTS as $k => $lib): ?><option value="<?= h($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($lib) ?></option><?php endforeach; ?>
              </select>
              <button class="mod-petit-btn" type="submit">Changer</button></form>
            <form method="post" data-confirmer="Supprimer définitivement ce signalement ?"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($r['id']) ?>"><input type="hidden" name="vue" value="<?= h($vue) ?>">
              <button class="mod-petit-btn mod-danger" name="action" value="supprimer">Supprimer</button></form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</main>
<script src="../assets/moderation.js"></script>
</body>
</html>
