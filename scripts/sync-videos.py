#!/usr/bin/env python3
"""Ajoute automatiquement à assets/conseils/conseils.json les vidéos des séances du conseil municipal
publiées par la Ville de Saint-André-de-Sangonis sur YouTube (onglet « En direct » en priorité, puis
« Vidéos » et le flux RSS), puis régénère la page via build-conseils.py.

- Ne retient que les titres contenant « conseil municipal » (insensible à la casse et aux accents).
- Date de la séance lue dans le titre ; à défaut, date de publication de la vidéo.
- Rien avant mars 2026. Une séance existante reçoit la vidéo ; sinon une séance est créée.
- Ne supprime jamais rien ; en cas d'échec réseau, ne modifie rien et sort avec le code 0.
Bibliothèque standard Python uniquement."""
import json, os, re, subprocess, sys, unicodedata, urllib.request
import xml.etree.ElementTree as ET

SITE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
JSON = os.path.join(SITE, 'assets/conseils/conseils.json')
CHAINE = 'https://www.youtube.com/@villedesaint-andre-de-sang6548'
CHANNEL_ID = 'UC9X4FF2FjlWzqZcbL-UgClA'
DEBUT = '2026-03-01'
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36'
MOIS = {'janvier':1,'fevrier':2,'mars':3,'avril':4,'mai':5,'juin':6,'juillet':7,'aout':8,'septembre':9,'octobre':10,'novembre':11,'decembre':12}

def get(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Language': 'fr-FR,fr;q=0.9',
                                               'Cookie': 'CONSENT=YES+cb; SOCS=CAI'})
    with urllib.request.urlopen(req, timeout=20) as r:
        return r.read().decode('utf-8', 'replace')

def norm(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s.lower()) if unicodedata.category(c) != 'Mn')

def est_conseil(titre):
    t = norm(titre)
    return 'conseil municipal' in t and not re.search(r'conseil municipal (des |d)?(jeunes|enfants)', t)

def date_titre(titre):
    t = norm(titre)
    m = re.search(r'\b(\d{1,2})(?:er)?\s+(' + '|'.join(MOIS) + r')\s+(20\d\d)\b', t)
    if m: return f'{int(m[3]):04d}-{MOIS[m[2]]:02d}-{int(m[1]):02d}'
    m = re.search(r'\b(\d{1,2})[/.](\d{1,2})[/.](20\d\d)\b', t)
    if m: return f'{int(m[3]):04d}-{int(m[2]):02d}-{int(m[1]):02d}'
    return None

def depuis_page(onglet):
    """Vidéos d'un onglet de la chaîne (ytInitialData) : [(id, titre)]."""
    html = get(f'{CHAINE}/{onglet}')
    m = re.search(r'var ytInitialData = (\{.*?\});</script>', html, re.S)
    if not m: return []
    out = []
    def walk(o):
        if isinstance(o, dict):
            l = o.get('lockupViewModel')
            if l and l.get('contentId'):
                try: out.append((l['contentId'], l['metadata']['lockupMetadataViewModel']['title']['content']))
                except KeyError: pass
            v = o.get('videoRenderer') or o.get('gridVideoRenderer')
            if v and v.get('videoId'):
                t = v.get('title', {}); t = t.get('simpleText') or ''.join(r.get('text', '') for r in t.get('runs', []))
                out.append((v['videoId'], t))
            for x in o.values(): walk(x)
        elif isinstance(o, list):
            for x in o: walk(x)
    walk(json.loads(m.group(1)))
    return out

def depuis_rss():
    ns = {'a': 'http://www.w3.org/2005/Atom', 'yt': 'http://www.youtube.com/xml/schemas/2015'}
    for q in (f'channel_id={CHANNEL_ID}', f'playlist_id=UU{CHANNEL_ID[2:]}'):
        try:
            root = ET.fromstring(get(f'https://www.youtube.com/feeds/videos.xml?{q}'))
            return [(e.find('yt:videoId', ns).text, e.find('a:title', ns).text) for e in root.findall('a:entry', ns)]
        except Exception:
            continue
    return []

def infos_video(vid):
    """Date de publication / début du direct et durée, lues sur la page de la vidéo."""
    try: html = get(f'https://www.youtube.com/watch?v={vid}')
    except Exception: return None, None
    d = re.search(r'"startTimestamp":"(\d{4}-\d\d-\d\d)', html) or re.search(r'"publishDate":"(\d{4}-\d\d-\d\d)', html)
    s = re.search(r'"lengthSeconds":"(\d+)"', html)
    duree = None
    if s and int(s[1]) > 0:
        n = int(s[1]); h, mn = n // 3600, round((n % 3600) / 60)
        if mn == 60: h, mn = h + 1, 0
        duree = f'{h} h {mn:02d}' if h else f'{mn} min'
    return (d[1] if d else None), duree

def main():
    trouvees, erreurs = {}, 0
    for source in (lambda: depuis_page('streams'), lambda: depuis_page('videos'), depuis_rss):
        try:
            for vid, titre in source():
                if re.fullmatch(r'[A-Za-z0-9_-]{11}', vid or '') and titre and vid not in trouvees:
                    trouvees[vid] = titre.strip()
        except Exception as e:
            erreurs += 1; print(f'  source indisponible : {e}', file=sys.stderr)
    if not trouvees:
        print('Aucune vidéo récupérée (YouTube indisponible ?) : rien à faire.'); return

    data = json.load(open(JSON, encoding='utf-8'))
    seances = {s['date']: s for s in data['seances']}
    connues = {s['video']['id'] for s in data['seances'] if s.get('video')}
    ajouts = 0
    for vid, titre in trouvees.items():
        if vid in connues or not est_conseil(titre): continue
        date, duree = date_titre(titre), None
        pub, duree = infos_video(vid)
        date = date or pub
        if not date or date < DEBUT: continue
        video = {'id': vid, 'titre': titre, 'duree': duree}
        s = seances.get(date)
        if s is None:
            installation = 'installation' in norm(titre)
            s = {'date': date, 'titre': 'Séance d’installation du conseil municipal' if installation else 'Conseil municipal',
                 'video': video, 'documents': []}
            data['seances'].append(s); seances[date] = s
        elif not s.get('video'):
            s['video'] = video
        else:
            continue   # une vidéo est déjà associée à cette séance : on ne la remplace pas
        ajouts += 1; print(f'  + {date} : {titre} ({vid})')
    if ajouts:
        data['seances'].sort(key=lambda s: s['date'], reverse=True)
        with open(JSON, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2); f.write('\n')
    print(f'{len(trouvees)} vidéos lues sur la chaîne, {ajouts} séance(s) mise(s) à jour.')
    subprocess.run([sys.executable, os.path.join(SITE, 'scripts/build-conseils.py')], check=True)

if __name__ == '__main__':
    main()
