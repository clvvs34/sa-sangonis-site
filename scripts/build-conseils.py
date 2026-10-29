#!/usr/bin/env python3
"""Génère, à partir de assets/conseils/conseils.json :
  - conseils/AAAA-MM-JJ.html : une page par séance (lecteur vidéo, documents, fil d'Ariane, séances précédente/suivante) ;
  - conseils/vignettes/<id>.jpg : copie locale de la vignette officielle de chaque vidéo YouTube (téléchargée une fois) ;
  - conseils-municipaux.html : l'index des séances (entre <!-- SEANCES:DEBUT --> et <!-- SEANCES:FIN -->) ;
  - index.html : l'encart « dernière séance » (entre <!-- ACCUEIL-SEANCES:DEBUT --> et <!-- ACCUEIL-SEANCES:FIN -->) ;
  - sitemap.xml (pages fixes + pages de séance).
Vidéos : « façade » (vignette locale + bouton lecture ; sans JavaScript, simple lien vers YouTube).
Le lecteur youtube-nocookie.com n'est chargé qu'au clic (assets/main.js) : rien ne vient de YouTube avant.
L'en-tête et le pied de page des pages de séance sont repris de conseils-municipaux.html (chemins adaptés).
Usage : python3 scripts/build-conseils.py   (bibliothèque standard uniquement)"""
import glob, json, os, re, sys, urllib.request
from html import escape

SITE = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
URL = 'https://sa-sangonis.fr/'
DEBUT_MANDAT = '2026-03-01'   # rien avant mars 2026
PAGES_FIXES = ['', 'conseils-municipaux.html', 'agenda.html', 'mentions-legales.html']
MOIS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']
TYPES = {'ordre-du-jour': 'Ordre du jour', 'proces-verbal': 'Procès-verbal'}
VIGNETTES = 'conseils/vignettes'
CHAINE = 'https://www.youtube.com/@villedesaint-andre-de-sang6548'

def date_fr(iso):
    y, m, d = (int(x) for x in iso.split('-'))
    return f"{d}{'er' if d == 1 else ''} {MOIS[m-1]} {y}"

def taille(path):
    n = os.path.getsize(path)
    return f"{max(1, round(n/1024))} Ko" if n < 1024*1024 else f"{n/1024/1024:.1f} Mo".replace('.', ',')

def lire(rel): return open(os.path.join(SITE, rel), encoding='utf-8').read()

def ecrire(rel, contenu):
    p = os.path.join(SITE, rel)
    if os.path.exists(p) and open(p, encoding='utf-8').read() == contenu: return
    os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, 'w', encoding='utf-8').write(contenu)

# ---------------------------------------------------------------- vidéos
def video_ok(s):
    v = s.get('video')
    return v if isinstance(v, dict) and re.fullmatch(r'[A-Za-z0-9_-]{11}', v.get('id') or '') else None

def vignette(vid_id):
    """Chemin (relatif au site) de la vignette locale, téléchargée si absente ; None si indisponible (réessai au prochain passage)."""
    rel = f'{VIGNETTES}/{vid_id}.jpg'
    p = os.path.join(SITE, rel)
    if os.path.isfile(p) and os.path.getsize(p) > 2000: return rel
    for q in ('sddefault', 'hqdefault'):   # 640x480 puis 480x360 ; les bandes noires sont recadrées en CSS (16:9)
        try:
            req = urllib.request.Request(f'https://i.ytimg.com/vi/{vid_id}/{q}.jpg',
                                         headers={'User-Agent': 'sa-sangonis.fr build-conseils (+https://sa-sangonis.fr/)'})
            with urllib.request.urlopen(req, timeout=20) as r:
                data = r.read()
            if len(data) > 2000 and data[:3] == b'\xff\xd8\xff':
                os.makedirs(os.path.dirname(p), exist_ok=True)
                open(p, 'wb').write(data); print(f'  + {rel}')
                return rel
        except Exception as e:
            print(f'  (vignette {q} indisponible pour {vid_id} : {e})')
    return None

def vignette_html(s, prefixe, cls, lazy=True, icone=True):
    """Vignette 16:9 (image locale + pictogramme lecture), ou bloc neutre si pas de vidéo / vignette indisponible."""
    v = video_ok(s)
    rel = vignette(v['id']) if v else None
    ico = '<span class="lecture-icone" aria-hidden="true"></span>' if icone and v else ''
    if not rel:
        txt = '' if v else '<span class="vignette-vide-txt">Pas de vidéo</span>'
        return f'<span class="{cls} vignette--vide" aria-hidden="true">{txt}{ico}</span>'
    charge = ' loading="lazy"' if lazy else ''
    return (f'<span class="{cls}" aria-hidden="true"><img src="{prefixe}{rel}" width="640" height="480" alt=""{charge} decoding="async">{ico}</span>')

def bloc_video(s):
    v = video_ok(s)
    if not v:
        return ('<div class="video-absente"><p>Pas de vidéo publiée pour cette séance.</p>'
                f'<p class="seance-meta">Les vidéos des séances sont publiées par la Ville sur <a href="{CHAINE}" rel="noopener">sa chaîne YouTube</a>.</p></div>')
    vid, yt = v['id'], f'https://www.youtube.com/watch?v={v["id"]}'
    titre = v.get('titre') or nom_seance(s)
    duree = f" · {escape(v['duree']).replace(' ', '&nbsp;')}" if v.get('duree') else ''
    return (f'<div class="video">\n'
            f'            <a class="video-facade" href="{yt}" data-yt="{vid}" data-titre="{escape(titre)}" rel="noopener" aria-label="Lire la vidéo : {escape(titre)}">\n'
            f'              {vignette_html(s, "../", "video-vignette", lazy=False, icone=False)}\n'
            f'              <span class="video-bouton" aria-hidden="true"></span>\n'
            f'              <span class="video-mention" aria-hidden="true">Lire la vidéo</span>\n'
            f'            </a>\n'
            f'          </div>\n'
            f'          <p class="seance-meta">Vidéo publiée par la Ville{duree} · <a href="{yt}" rel="noopener">Voir sur YouTube</a></p>\n'
            f'          <p class="video-rgpd">Le lecteur YouTube (mode confidentialité renforcée) n’est chargé qu’au clic sur « Lire ».</p>')

# ---------------------------------------------------------------- documents
def documents(s):
    """Documents publiables de la séance (fichier présent), ordre du jour puis procès-verbal."""
    out = []
    for d in s.get('documents', []):
        f, t = d['fichier'], d.get('type')
        if t not in TYPES: sys.exit(f"Type de document non publiable : {t} ({f})")
        if not re.fullmatch(r'assets/conseils/\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.pdf', f): sys.exit(f"Chemin invalide : {f}")
        if not os.path.isfile(os.path.join(SITE, f)): print(f"  (non publié, fichier absent : {f})"); continue
        out.append(d)
    return sorted(out, key=lambda d: list(TYPES).index(d['type']))

def resume(s):
    """Indications courtes : « Vidéo · Ordre du jour · Procès-verbal en attente »."""
    r = ['Vidéo'] if video_ok(s) else []
    r += [TYPES[d['type']] for d in documents(s)]
    if s.get('pv_en_attente') and not any(d['type'] == 'proces-verbal' for d in documents(s)):
        r.append('Procès-verbal en attente')
    return ' · '.join(r) or 'Documents à venir'

def nom_seance(s):
    return f"{s['titre']} du {date_fr(s['date'])}"

# ---------------------------------------------------------------- gabarit des pages de séance
def gabarit():
    src = lire('conseils-municipaux.html')
    head = src[:src.index('</head>')]
    corps = src[src.index('<body>'):src.index('<main id="contenu">')]
    pied = src[src.index('</main>'):]
    def prefixer(h):
        return re.sub(r'(href|src)="(?!https?:|mailto:|#|/|\.\./)([^"]*)"', r'\1="../\2"', h)
    return prefixer(head), prefixer(corps), prefixer(pied)

def page_seance(s, prec, suiv, gab):
    head, corps, pied = gab
    iso, nom = s['date'], nom_seance(s)
    desc = f"{nom} à Saint-André-de-Sangonis : vidéo de la séance et documents du conseil (ordre du jour, procès-verbal)."
    h = head
    h = re.sub(r'<title>.*?</title>', f'<title>{escape(nom)} — Sangonis Cœur d’Hérault</title>', h)
    h = re.sub(r'<meta name="description" content="[^"]*">', f'<meta name="description" content="{escape(desc)}">', h)
    h = re.sub(r'<link rel="canonical" href="[^"]*">', f'<link rel="canonical" href="{URL}conseils/{iso}.html">', h)
    h = re.sub(r'<meta property="og:url" content="[^"]*">', f'<meta property="og:url" content="{URL}conseils/{iso}.html">', h)
    h = re.sub(r'<meta property="og:title" content="[^"]*">', f'<meta property="og:title" content="{escape(nom)}">', h)
    h = re.sub(r'<meta property="og:description" content="[^"]*">', f'<meta property="og:description" content="{escape(desc)}">', h)

    video = bloc_video(s)
    lignes = [f'<li><a href="../{escape(d["fichier"])}">{TYPES[d["type"]]}</a> <span class="doc-meta">(PDF, {taille(os.path.join(SITE, d["fichier"]))})</span></li>'
              for d in documents(s)]
    if s.get('pv_en_attente') and not any(d['type'] == 'proces-verbal' for d in documents(s)):
        lignes.append('<li class="doc-attente">Procès-verbal : en attente d’approbation</li>')
    docs = ('<ul class="seance-docs">\n' + '\n'.join('            ' + l for l in lignes) + '\n          </ul>') if lignes \
        else '<p class="seance-vide">Pas de document publié pour l’instant.</p>'
    objet = f'\n    <p class="page-titre-lead">{escape(s["sous_titre"])}</p>' if s.get('sous_titre') else ''
    def lien(x, sens):
        if not x: return '<span></span>'
        return (f'<a class="seance-nav-{sens}" href="{x["date"]}.html"><span class="seance-nav-label">'
                f'{"Séance précédente" if sens == "prec" else "Séance suivante"}</span>{escape(nom_seance(x))}</a>')
    main = f'''<main id="contenu">
<div class="page-titre">
  <div class="wrap">
    <nav class="ariane" aria-label="Fil d’Ariane"><ol><li><a href="../index.html">Accueil</a></li><li><a href="../conseils-municipaux.html">Conseils municipaux</a></li><li aria-current="page">Séance du {date_fr(iso)}</li></ol></nav>
    <h1><span class="h1-sur">{escape(s["titre"])}</span><time datetime="{iso}">{date_fr(iso)}</time></h1>{objet}
  </div>
  <svg class="hero-wave" viewBox="0 0 1440 70" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M0 38 C240 4 420 66 720 36 S1200 6 1440 30 V70 H0Z" fill="#fff"/></svg>
</div>

<section class="seances-section" aria-label="Vidéo et documents de la séance">
  <div class="wrap">
    <article class="seance seance--page{'' if video_ok(s) else ' seance--sans-video'}">
      <div class="seance-blocs">
        <div class="seance-bloc seance-bloc--video">
          <h2 class="seance-label">Vidéo de la séance</h2>
          {video}
        </div>
        <div class="seance-bloc">
          <h2 class="seance-label">Documents du conseil</h2>
          {docs}
        </div>
      </div>
    </article>
    <nav class="seance-nav" aria-label="Autres séances">
      {lien(prec, "prec")}
      {lien(suiv, "suiv")}
    </nav>
    <p class="seances-source"><a href="../conseils-municipaux.html">Toutes les séances</a>. Vidéo publiée par la Ville de Saint-André-de-Sangonis sur <a href="{CHAINE}" rel="noopener">sa chaîne YouTube</a>, lue en mode confidentialité renforcée (youtube-nocookie.com) : rien n’est chargé depuis YouTube avant le clic sur « Lire ». Documents officiels : <a href="https://saintandredesangonis.fr/conseil-municipal/">site de la mairie</a>.</p>
  </div>
</section>
'''
    return h + '</head>\n' + corps + main + pied

# ---------------------------------------------------------------- index, accueil, sitemap
def remplacer(rel, nom, contenu):
    s = lire(rel)
    pat = re.compile(rf'(<!-- {nom}:DEBUT -->).*?(\n[ \t]*<!-- {nom}:FIN -->)', re.S)
    if not pat.search(s): sys.exit(f"Marqueurs {nom} absents de {rel}")
    ecrire(rel, pat.sub(lambda m: m.group(1) + '\n' + contenu + m.group(2), s))

def index_html(seances):
    blocs, annee = [], None
    for s in seances:
        y = s['date'][:4]
        if y != annee:
            if annee: blocs.append('    </ol>')
            blocs.append(f'    <h3 class="seances-annee">{y}</h3>\n    <ol class="seances-liste">'); annee = y
        objet = f'<span class="seance-ligne-objet">{escape(s["sous_titre"])}</span>' if s.get('sous_titre') else ''
        blocs.append(f'      <li><a class="seance-ligne" href="conseils/{s["date"]}.html">'
                     f'{vignette_html(s, "", "seance-ligne-vignette")}'
                     f'<span class="seance-ligne-texte"><time datetime="{s["date"]}">{date_fr(s["date"])}</time>'
                     f'<span class="seance-ligne-titre">{escape(s["titre"])}</span>{objet}'
                     f'<span class="seance-ligne-resume">{resume(s)}</span></span></a></li>')
    if annee: blocs.append('    </ol>')
    return '\n'.join(blocs)

def accueil_html(s):
    return (f'        <a class="teaser-conseil-video" href="conseils/{s["date"]}.html" tabindex="-1" aria-hidden="true">'
            f'{vignette_html(s, "", "teaser-conseil-vignette")}</a>\n'
            f'        <p class="teaser-conseil-label">Dernière séance</p>\n'
            f'        <p class="teaser-conseil-titre"><a href="conseils/{s["date"]}.html">{escape(nom_seance(s))}</a></p>\n'
            f'        <p class="teaser-conseil-resume">{resume(s)}</p>')

def sitemap(seances):
    urls = [URL + p for p in PAGES_FIXES] + [f'{URL}conseils/{s["date"]}.html' for s in seances]
    return ('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
            + ''.join(f'  <url><loc>{u}</loc></url>\n' for u in urls) + '</urlset>\n')

def main():
    data = json.load(open(os.path.join(SITE, 'assets/conseils/conseils.json'), encoding='utf-8'))
    seances = sorted((s for s in data['seances'] if s['date'] >= DEBUT_MANDAT), key=lambda s: s['date'], reverse=True)
    for s in seances:
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', s['date']): sys.exit(f"Date invalide : {s['date']}")
    gab = gabarit()
    attendues = set()
    for i, s in enumerate(seances):
        prec = seances[i + 1] if i + 1 < len(seances) else None   # plus ancienne
        suiv = seances[i - 1] if i > 0 else None                   # plus récente
        rel = f'conseils/{s["date"]}.html'; attendues.add(rel)
        ecrire(rel, page_seance(s, prec, suiv, gab))
    for f in glob.glob(os.path.join(SITE, 'conseils', '*.html')):   # séances retirées du JSON
        rel = os.path.relpath(f, SITE)
        if re.fullmatch(r'conseils/\d{4}-\d{2}-\d{2}\.html', rel) and rel not in attendues:
            os.remove(f); print(f'  - {rel} supprimée')
    ids = {video_ok(s)['id'] for s in seances if video_ok(s)}
    for f in glob.glob(os.path.join(SITE, VIGNETTES, '*.jpg')):     # vignettes devenues inutiles
        if os.path.basename(f)[:-4] not in ids:
            os.remove(f); print(f'  - {os.path.relpath(f, SITE)} supprimée')
    remplacer('conseils-municipaux.html', 'SEANCES', index_html(seances))
    if seances: remplacer('index.html', 'ACCUEIL-SEANCES', accueil_html(seances[0]))
    ecrire('sitemap.xml', sitemap(seances))
    print(f"{len(seances)} séances générées.")

if __name__ == '__main__':
    main()
