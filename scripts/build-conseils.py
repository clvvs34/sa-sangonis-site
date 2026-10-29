#!/usr/bin/env python3
"""Génère la liste des séances (conseils-municipaux.html) et l'encart d'accueil (index.html)
à partir de assets/conseils/conseils.json. Remplace le contenu entre les marqueurs
<!-- SEANCES:DEBUT --> / <!-- SEANCES:FIN --> et <!-- ACCUEIL-SEANCES:DEBUT --> / <!-- ACCUEIL-SEANCES:FIN -->."""
import json, os, re, sys
from html import escape

SITE = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEBUT_MANDAT = '2026-03-01'   # rien avant mars 2026
MOIS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']
TYPES = {'ordre-du-jour': 'Ordre du jour', 'proces-verbal': 'Procès-verbal', 'deliberation': 'Délibération',
         'compte-rendu': 'Compte rendu', 'autre': 'Document'}
ORDRE = list(TYPES)

def date_fr(iso):
    y, m, d = (int(x) for x in iso.split('-'))
    return f"{d}{'er' if d == 1 else ''} {MOIS[m-1]} {y}"

def taille(path):
    n = os.path.getsize(path)
    return f"{max(1, round(n/1024))} Ko" if n < 1024*1024 else f"{n/1024/1024:.1f} Mo".replace('.', ',')

def doc_html(doc):
    f = doc['fichier']
    if not re.fullmatch(r'assets/conseils/\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.pdf', f):
        sys.exit(f"Chemin de document invalide : {f}")
    p = os.path.join(SITE, f)
    if not os.path.isfile(p):
        print(f"  (non publié, fichier absent : {f})")
        return None
    t = doc.get('type', 'autre')
    if t not in TYPES: sys.exit(f"Type inconnu : {t}")
    label = escape(doc.get('titre') or TYPES[t])
    return (f'<li><a href="{escape(f)}">{label}</a> '
            f'<span class="doc-meta">(PDF, {taille(p)})</span></li>')

def seance_html(s):
    iso = s['date']
    vid = s.get('video')
    if vid:
        assert re.fullmatch(r'[A-Za-z0-9_-]{11}', vid['id'])
        meta = 'Vidéo publiée par la Ville' + (f" · {escape(vid['duree'])}" if vid.get('duree') else '')
        video = (f'<a class="seance-lien" href="https://www.youtube.com/watch?v={vid["id"]}" rel="noopener" '
                 f'aria-label="Regarder sur YouTube : {escape(s["titre"])} du {date_fr(iso)}">Regarder sur YouTube</a>\n'
                 f'            <p class="seance-meta">{meta}</p>')
    else:
        video = '<p class="seance-vide">Pas de vidéo publiée pour cette séance.</p>'
    docs = sorted(s.get('documents', []), key=lambda d: ORDRE.index(d.get('type', 'autre')) if d.get('type', 'autre') in ORDRE else 99)
    docs = [d for d in docs if doc_html(d)]
    lignes = ['              ' + doc_html(d) for d in docs]
    if s.get('pv_en_attente'):
        lignes.append('              <li class="doc-attente">Procès-verbal : en attente d’approbation</li>')
    if lignes:
        docs_html = '<ul class="seance-docs">\n' + '\n'.join(lignes) + '\n            </ul>'
    else:
        docs_html = '<p class="seance-vide">Pas de document publié pour l’instant.</p>'
    sous = f'\n        <p class="seance-objet">{escape(s["sous_titre"])}</p>' if s.get('sous_titre') else ''
    return f'''      <article class="seance" id="seance-{iso}">
        <h4 class="seance-entete"><time datetime="{iso}">{date_fr(iso)}</time><span>{escape(s["titre"])}</span></h4>{sous}
        <div class="seance-blocs">
          <div class="seance-bloc">
            <p class="seance-label">Vidéo de la séance</p>
            {video}
          </div>
          <div class="seance-bloc">
            <p class="seance-label">Documents</p>
            {docs_html}
          </div>
        </div>
      </article>'''

def remplacer(path, nom, contenu):
    s = open(path, encoding='utf-8').read()
    pat = re.compile(rf'(<!-- {nom}:DEBUT -->).*?(\n[ \t]*<!-- {nom}:FIN -->)', re.S)
    if not pat.search(s): sys.exit(f"Marqueurs {nom} absents de {path}")
    s = pat.sub(lambda m: m.group(1) + '\n' + contenu + m.group(2), s)
    open(path, 'w', encoding='utf-8').write(s)

data = json.load(open(os.path.join(SITE, 'assets/conseils/conseils.json'), encoding='utf-8'))
seances = sorted((s for s in data['seances'] if s['date'] >= DEBUT_MANDAT), key=lambda s: s['date'], reverse=True)

blocs, annee = [], None
for s in seances:
    y = s['date'][:4]
    if y != annee:
        if annee: blocs.append('    </div>')
        blocs.append(f'    <h3 class="seances-annee">{y}</h3>\n    <div class="seances">')
        annee = y
    blocs.append(seance_html(s))
if annee: blocs.append('    </div>')
remplacer(os.path.join(SITE, 'conseils-municipaux.html'), 'SEANCES', '\n'.join(blocs))

acc = [f'          <li><a href="conseils-municipaux.html#seance-{s["date"]}">{escape(s["titre"])} du {date_fr(s["date"])}</a></li>'
       for s in seances[:3]]
remplacer(os.path.join(SITE, 'index.html'), 'ACCUEIL-SEANCES', '\n'.join(acc))
print(f"{len(seances)} séances générées.")
