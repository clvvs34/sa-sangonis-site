<?php
/**
 * Signalements citoyens — fonctions communes.
 * Sangonis Cœur d’Hérault (sa-sangonis.fr)
 *
 * Données stockées HORS de la racine web : <dossier parent de www>/signalements-data
 * (repli : www/data, protégé par .htaccess, si le dossier parent n’est pas accessible en écriture).
 */
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const SG_BBOX = ['latMin' => 43.600, 'latMax' => 43.700, 'lngMin' => 3.455, 'lngMax' => 3.556];
const SG_CATEGORIES = [
    'voirie'      => 'Voirie / chaussée',
    'eclairage'   => 'Éclairage public',
    'proprete'    => 'Propreté / dépôts sauvages',
    'signalisation' => 'Signalisation',
    'espaces_verts' => 'Espaces verts',
    'batiment'    => 'Bâtiment ou équipement public',
    'autre'       => 'Autre',
];
const SG_STATUTS = [
    'signale'  => 'Signalé',
    'transmis' => 'Transmis à la mairie',
    'resolu'   => 'Résolu',
];
const SG_MAX_UPLOAD   = 8 * 1024 * 1024;   // 8 Mo par photo envoyée
const SG_STORAGE_CAP  = 60 * 1024 * 1024;  // 60 Mo au total pour les données
const SG_MAX_REPORTS  = 3000;              // garde-fou sur le nombre de fichiers
const SG_MAX_PENDING  = 300;               // garde-fou contre l’inondation de la file d’attente
const SG_MAX_SIDE     = 1600;              // côté max des photos ré-encodées
const SG_MAX_PIXELS   = 40000000;          // 40 Mpx max avant décodage
const SG_RATE_CREATE  = 5;                 // signalements par heure et par IP (hachée)
const SG_RATE_LOGIN   = 5;                 // échecs de connexion par 15 min et par IP (hachée)
const SG_RETENTION_RESOLU = 365 * 86400;   // suppression 12 mois après résolution
const SG_IP_RETENTION = 86400;             // empreintes d’IP conservées 24 h

/* ------------------------------------------------------------------ */
/* Dossier de données                                                  */
/* ------------------------------------------------------------------ */

function sg_webroot(): string { return dirname(__DIR__, 2); }

function sg_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $www = sg_webroot();
    $outside = dirname($www) . '/signalements-data';
    $inside  = $www . '/data';

    foreach ([$outside, $inside] as $candidate) {
        if (@is_dir($candidate) && @is_writable($candidate)) { $dir = $candidate; break; }
        if ($candidate === $outside && !@is_dir($outside) && @is_writable(dirname($www))) {
            if (@mkdir($outside, 0700, true)) { $dir = $outside; break; }
        }
        if ($candidate === $inside && !@is_dir($inside)) {
            if (@mkdir($inside, 0700, true)) { $dir = $inside; break; }
        }
    }
    if ($dir === null) {
        sg_json(['ok' => false, 'message' => 'Le service est momentanément indisponible (stockage).'], 503);
    }
    foreach (['reports', 'photos', 'sessions'] as $sub) {
        if (!is_dir("$dir/$sub")) @mkdir("$dir/$sub", 0700, true);
    }
    // Protection, même hors racine web (inoffensif) et indispensable en repli dans www/data.
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
    return $dir;
}

/* ------------------------------------------------------------------ */
/* Fichiers : lecture/écriture atomiques                               */
/* ------------------------------------------------------------------ */

function sg_write_atomic(string $path, string $content): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/** Exécute $fn sous verrou exclusif (flock) sur un fichier de verrou nommé. */
function sg_with_lock(string $name, callable $fn)
{
    $lock = sg_data_dir() . '/' . $name . '.lock';
    $fh = @fopen($lock, 'c');
    if (!$fh) return $fn();
    try {
        flock($fh, LOCK_EX);
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function sg_read_json(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function sg_write_json(string $path, array $data): bool
{
    return sg_write_atomic($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/* ------------------------------------------------------------------ */
/* Configuration (config.php hors www : JSON derrière un garde PHP)     */
/* ------------------------------------------------------------------ */

const SG_CONFIG_GUARD = "<?php http_response_code(404); exit; ?>\n";

function sg_config(): array
{
    $path = sg_data_dir() . '/config.php';
    $cfg = [];
    $raw = @file_get_contents($path);
    if ($raw !== false) {
        $json = substr($raw, strlen(SG_CONFIG_GUARD));
        $cfg = json_decode((string)$json, true) ?: [];
    }
    if (empty($cfg['secret'])) {
        // Clé aléatoire servant à hacher les IP et à signer les jetons de formulaire.
        $cfg = sg_with_lock('config', function () use ($path) {
            $raw = @file_get_contents($path);
            $cur = $raw !== false ? (json_decode((string)substr($raw, strlen(SG_CONFIG_GUARD)), true) ?: []) : [];
            if (empty($cur['secret'])) {
                $cur['secret'] = bin2hex(random_bytes(32));
                sg_save_config_raw($path, $cur);
            }
            return $cur;
        });
    }
    return $cfg;
}

function sg_save_config_raw(string $path, array $cfg): bool
{
    return sg_write_atomic($path, SG_CONFIG_GUARD . json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function sg_update_config(callable $mutator): bool
{
    $path = sg_data_dir() . '/config.php';
    return (bool) sg_with_lock('config', function () use ($path, $mutator) {
        $raw = @file_get_contents($path);
        $cur = $raw !== false ? (json_decode((string)substr($raw, strlen(SG_CONFIG_GUARD)), true) ?: []) : [];
        $new = $mutator($cur);
        if ($new === false) return false;
        return sg_save_config_raw($path, $new);
    });
}

/* ------------------------------------------------------------------ */
/* Réponses HTTP                                                       */
/* ------------------------------------------------------------------ */

function sg_common_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Expires: 0'); // empêche mod_expires d’ajouter un cache aux réponses PHP
}

function sg_json(array $data, int $code = 200): void
{
    http_response_code($code);
    sg_common_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sg_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

function sg_is_local(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

/** Vérifie que la requête POST vient bien du site (protection CSRF de base). */
function sg_same_origin(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $p = parse_url($_SERVER[$h]);
            $oh = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
            return strtolower($oh) === strtolower($host);
        }
    }
    return true; // ni Origin ni Referer (rare) : on laisse passer, les autres contrôles s’appliquent
}

/* ------------------------------------------------------------------ */
/* Anti-abus : IP hachée, limite de débit, jeton horodaté               */
/* ------------------------------------------------------------------ */

function sg_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash_hmac('sha256', $ip, sg_config()['secret']), 0, 32);
}

/**
 * Enregistre un « coup » pour $bucket et indique si la limite est dépassée.
 * Fichier unique ratelimit.json, purgé des entrées de plus de 24 h.
 */
function sg_rate_hit(string $bucket, int $max, int $window, bool $record = true): bool
{
    $path = sg_data_dir() . '/ratelimit.json';
    $key = $bucket . ':' . sg_ip_hash();
    return (bool) sg_with_lock('ratelimit', function () use ($path, $key, $max, $window, $record) {
        $now = time();
        $all = sg_read_json($path) ?? [];
        foreach ($all as $k => $hits) {
            $all[$k] = array_values(array_filter((array)$hits, fn($t) => is_int($t) && $t > $now - SG_IP_RETENTION));
            if (!$all[$k]) unset($all[$k]);
        }
        $recent = array_filter($all[$key] ?? [], fn($t) => $t > $now - $window);
        $limited = count($recent) >= $max;
        if ($record && !$limited) $all[$key][] = $now;
        sg_write_json($path, $all);
        return $limited;
    });
}

function sg_rate_count(string $bucket, int $window): int
{
    $all = sg_read_json(sg_data_dir() . '/ratelimit.json') ?? [];
    $key = $bucket . ':' . sg_ip_hash();
    $now = time();
    return count(array_filter($all[$key] ?? [], fn($t) => $t > $now - $window));
}

function sg_rate_reset(string $bucket): void
{
    $path = sg_data_dir() . '/ratelimit.json';
    $key = $bucket . ':' . sg_ip_hash();
    sg_with_lock('ratelimit', function () use ($path, $key) {
        $all = sg_read_json($path) ?? [];
        unset($all[$key]);
        sg_write_json($path, $all);
    });
}

function sg_form_token(): string
{
    $t = (string) time();
    return $t . '.' . substr(hash_hmac('sha256', 'form|' . $t, sg_config()['secret']), 0, 32);
}

/** Retourne null si le jeton est valide, sinon un message d’erreur. */
function sg_check_form_token(string $token, int $minAge = 3, int $maxAge = 7200): ?string
{
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) return 'Formulaire expiré : rechargez la page.';
    $expected = substr(hash_hmac('sha256', 'form|' . $m[1], sg_config()['secret']), 0, 32);
    if (!hash_equals($expected, $m[2])) return 'Formulaire expiré : rechargez la page.';
    $age = time() - (int)$m[1];
    if ($age < $minAge) return 'Envoi trop rapide. Patientez quelques secondes puis réessayez.';
    if ($age > $maxAge) return 'Formulaire expiré : rechargez la page.';
    return null;
}

/* ------------------------------------------------------------------ */
/* Signalements                                                        */
/* ------------------------------------------------------------------ */

function sg_valid_id(string $id): bool { return (bool) preg_match('/^[a-f0-9]{16}$/', $id); }

function sg_report_path(string $id): string { return sg_data_dir() . '/reports/' . $id . '.json'; }

function sg_load_report(string $id): ?array
{
    if (!sg_valid_id($id)) return null;
    return sg_read_json(sg_report_path($id));
}

function sg_save_report(array $r): bool { return sg_write_json(sg_report_path($r['id']), $r); }

function sg_photo_path(array $r): ?string
{
    if (empty($r['photo']) || !preg_match('/^[a-f0-9]{16}\.(jpg|png|webp)$/', (string)$r['photo'])) return null;
    return sg_data_dir() . '/photos/' . $r['photo'];
}

function sg_delete_report(string $id): bool
{
    $r = sg_load_report($id);
    if (!$r) return false;
    $p = sg_photo_path($r);
    if ($p && is_file($p)) @unlink($p);
    return @unlink(sg_report_path($id));
}

/** @return array<int,array> tous les signalements, du plus récent au plus ancien */
function sg_all_reports(): array
{
    $out = [];
    foreach (glob(sg_data_dir() . '/reports/*.json') ?: [] as $f) {
        $r = sg_read_json($f);
        if ($r && isset($r['id']) && sg_valid_id((string)$r['id'])) $out[] = $r;
    }
    usort($out, fn($a, $b) => strcmp((string)$b['created'], (string)$a['created']));
    return $out;
}

function sg_storage_used(): int
{
    $total = 0;
    foreach (['reports', 'photos'] as $sub) {
        foreach (glob(sg_data_dir() . "/$sub/*") ?: [] as $f) $total += (int) @filesize($f);
    }
    return $total;
}

/** Suppression automatique : signalements résolus depuis plus de 12 mois. */
function sg_purge_old(): int
{
    $n = 0;
    $limit = time() - SG_RETENTION_RESOLU;
    foreach (sg_all_reports() as $r) {
        $ts = !empty($r['resolved_at']) ? strtotime((string)$r['resolved_at']) : false;
        if (($r['status'] ?? '') === 'resolu' && $ts !== false && $ts < $limit) {
            if (sg_delete_report($r['id'])) $n++;
        }
    }
    return $n;
}

function sg_in_commune(float $lat, float $lng): bool
{
    return $lat >= SG_BBOX['latMin'] && $lat <= SG_BBOX['latMax'] && $lng >= SG_BBOX['lngMin'] && $lng <= SG_BBOX['lngMax'];
}

function sg_public_view(array $r, string $photoBase): array
{
    return [
        'id'        => $r['id'],
        'categorie' => $r['category'],
        'categorie_libelle' => SG_CATEGORIES[$r['category']] ?? 'Autre',
        'lat'       => round((float)$r['lat'], 5),
        'lng'       => round((float)$r['lng'], 5),
        'commentaire' => (string)($r['comment'] ?? ''),
        'photo'     => sg_photo_path($r) ? $photoBase . $r['id'] : null,
        'date'      => substr((string)$r['created'], 0, 10),
        'statut'    => $r['status'],
        'statut_libelle' => SG_STATUTS[$r['status']] ?? 'Signalé',
    ];
}

/* ------------------------------------------------------------------ */
/* Images                                                              */
/* ------------------------------------------------------------------ */

/**
 * Valide et prépare une photo envoyée.
 * Avec GD : ré-encodage JPEG (supprime toutes les métadonnées, dont la position GPS).
 * Sans GD : contrôle du type réel (finfo) et suppression des segments EXIF des JPEG.
 * @return array{0:?string,1:?string,2:?string} [contenu, extension, erreur]
 */
function sg_process_image(string $tmpPath): array
{
    $size = (int) @filesize($tmpPath);
    if ($size <= 0) return [null, null, 'Photo illisible.'];
    if ($size > SG_MAX_UPLOAD) return [null, null, 'Photo trop lourde (8 Mo maximum).'];

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($fi, $tmpPath);
        finfo_close($fi);
    } else {
        $info = @getimagesize($tmpPath);
        $mime = $info['mime'] ?? '';
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) return [null, null, 'Format de photo non pris en charge (JPEG, PNG ou WebP).'];

    $dim = @getimagesize($tmpPath);
    if (!$dim || $dim[0] < 1 || $dim[1] < 1) return [null, null, 'Photo illisible.'];
    if ($dim[0] * $dim[1] > SG_MAX_PIXELS) return [null, null, 'Photo trop grande (dimensions).'];

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        @ini_set('memory_limit', '256M');
        $src = @imagecreatefromstring((string) file_get_contents($tmpPath));
        if (!$src) return [null, null, 'Photo illisible.'];
        // Orientation EXIF (photos envoyées sans redimensionnement côté navigateur)
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmpPath);
            $o = (int)($exif['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($angle) { $rot = imagerotate($src, $angle, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
        }
        $w = imagesx($src); $h = imagesy($src);
        $scale = min(1, SG_MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white); // fond blanc pour les PNG transparents
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start();
        imagejpeg($dst, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($dst);
        if ($out === '') return [null, null, 'Photo illisible.'];
        return [$out, 'jpg', null];
    }

    $data = (string) file_get_contents($tmpPath);
    if ($ext === 'jpg') $data = sg_strip_jpeg_metadata($data);
    return [$data, $ext, null];
}

/** Retire les segments APP1..APP15 (EXIF, XMP, GPS…) et COM d’un JPEG, sans ré-encoder. */
function sg_strip_jpeg_metadata(string $jpg): string
{
    if (substr($jpg, 0, 2) !== "\xFF\xD8") return $jpg;
    $out = "\xFF\xD8";
    $i = 2; $len = strlen($jpg);
    while ($i + 4 <= $len) {
        if ($jpg[$i] !== "\xFF") return $jpg; // structure inattendue : on rend l’original
        $marker = ord($jpg[$i + 1]);
        if ($marker === 0xDA) { $out .= substr($jpg, $i); return $out; } // début des données image
        $segLen = (ord($jpg[$i + 2]) << 8) | ord($jpg[$i + 3]);
        if ($segLen < 2 || $i + 2 + $segLen > $len) return $jpg;
        $isMeta = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE;
        if (!$isMeta) $out .= substr($jpg, $i, 2 + $segLen);
        $i += 2 + $segLen;
    }
    return $jpg;
}
