#!/usr/bin/env python3
"""Synchronise l'agenda de la Ville de Saint-André-de-Sangonis (The Events Calendar, API REST publique)
vers des fichiers statiques du site :
  - assets/agenda.json (données brutes nettoyées),
  - agenda.html (liste complète, entre <!-- AGENDA:DEBUT --> et <!-- AGENDA:FIN -->),
  - index.html (4 prochains rendez-vous, entre <!-- AGENDA-ACCUEIL:DEBUT --> et <!-- AGENDA-ACCUEIL:FIN -->).
Aucune image distante. En cas d'échec réseau, ne modifie rien (code 0). Bibliothèque standard uniquement."""
import datetime as dt, html, json, os, re, sys, urllib.parse, urllib.request
from zoneinfo import ZoneInfo

SITE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
API = 'https://saintandredesangonis.fr/wp-json/tribe/events/v1/events'
ORIGINE = 'https://saintandredesangonis.fr/'
TZ = ZoneInfo('Europe/Paris')
MAX_EVENTS, MAX_ACCUEIL, HORIZON_JOURS = 60, 4, 180
JOURS = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche']
MOIS_C = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.']
MOIS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']

def texte(s):
    s = re.sub(r'<[^>]+>', ' ', s or '')
    return re.sub(r'\s+', ' ', html.unescape(s)).strip()

def recuperer():
    maintenant = dt.datetime.now(TZ)
    params = {'start_date': maintenant.strftime('%Y-%m-%d 00:00:00'),
              'end_date': (maintenant + dt.timedelta(days=HORIZON_JOURS)).strftime('%Y-%m-%d 23:59:59'),
              'per_page': 50, 'status': 'publish'}
    evts, page = [], 1
    while len(evts) < MAX_EVENTS and page <= 4:
        params['page'] = page
        req = urllib.request.Request(API + '?' + urllib.parse.urlencode(params),
                                     headers={'User-Agent': 'sa-sangonis.fr agenda-sync (+https://sa-sangonis.fr/)', 'Accept': 'application/json'})
        with urllib.request.urlopen(req, timeout=30) as r:
            d = json.load(r)
        evts += d.get('events', [])
        if page >= int(d.get('total_pages') or 1): break
        page += 1
    out = []
    for e in evts:
        url = e.get('url') or ''
        if not url.startswith(ORIGINE): continue
        v = e.get('venue') if isinstance(e.get('venue'), dict) else {}
        lieu = texte(v.get('venue'))
        ville = texte(v.get('city'))
        if ville and 'sangonis' not in ville.lower() and ville.lower() not in lieu.lower(): lieu = f'{lieu}, {ville}' if lieu else ville
        out.append({'titre': texte(e.get('title')), 'debut': e.get('start_date'), 'fin': e.get('end_date'),
                    'journee': bool(e.get('all_day')), 'lieu': lieu or None, 'url': url})
    fin_min = dt.datetime.now(TZ).strftime('%Y-%m-%d %H:%M:%S')
    out = [x for x in out if x['titre'] and x['debut'] and (x['fin'] or x['debut']) >= fin_min]
    out.sort(key=lambda x: x['debut'])
    return out[:MAX_EVENTS]

def p(s): return dt.datetime.strptime(s, '%Y-%m-%d %H:%M:%S')
def heure(d): return f'{d.hour} h' + (f' {d.minute:02d}' if d.minute else '')
def jour(d): return f'{JOURS[d.weekday()]} {d.day}{"er" if d.day == 1 else ""} {MOIS[d.month-1]}'

def quand(e):
    a, b = p(e['debut']), p(e['fin'] or e['debut'])
    if e['journee']:
        return jour(a) if a.date() == b.date() else f'du {jour(a)} au {jour(b)}'
    if a.date() == b.date() or (b - a) < dt.timedelta(hours=12):
        return f'{jour(a)}, {heure(a)}' + (f' – {heure(b)}' if b > a else '')
    return f'du {jour(a)}, {heure(a)} au {jour(b)}, {heure(b)}'

def item(e, ind):
    a = p(e['debut'])
    lieu = f'\n{ind}    <p class="evt-lieu">{html.escape(e["lieu"])}</p>' if e['lieu'] else ''
    q = quand(e); q = q[0].upper() + q[1:]
    return (f'{ind}<li class="evt" data-fin="{html.escape(e["fin"] or e["debut"])}">\n'
            f'{ind}  <time class="evt-date" datetime="{a:%Y-%m-%d}{'' if e['journee'] else format(a, 'T%H:%M')}"><span class="evt-jour">{a.day}</span><span class="evt-mois">{MOIS_C[a.month-1]}</span></time>\n'
            f'{ind}  <div class="evt-corps">\n'
            f'{ind}    <p class="evt-titre"><a href="{html.escape(e["url"])}" rel="noopener">{html.escape(e["titre"])}</a></p>\n'
            f'{ind}    <p class="evt-quand">{html.escape(q)}</p>{lieu}\n'
            f'{ind}  </div>\n{ind}</li>')

def liste(evts, ind, vide):
    if not evts: return f'{ind}<p class="evt-vide">{vide}</p>'
    return f'{ind}<ol class="evts">\n' + '\n'.join(item(e, ind + '  ') for e in evts) + f'\n{ind}</ol>'

def page_complete(evts):
    if not evts: return liste([], '    ', 'Aucun rendez-vous annoncé pour le moment.')
    blocs, cle = [], None
    groupes = {}
    for e in evts:
        a = p(e['debut']); groupes.setdefault((a.year, a.month), []).append(e)
    for (y, m), es in groupes.items():
        blocs.append(f'    <h3 class="evt-mois-titre">{MOIS[m-1].capitalize()} {y}</h3>\n' + liste(es, '    ', ''))
    return '\n'.join(blocs)

def remplacer(chemin, nom, contenu):
    s = open(chemin, encoding='utf-8').read()
    pat = re.compile(rf'(<!-- {nom}:DEBUT -->).*?(\n[ \t]*<!-- {nom}:FIN -->)', re.S)
    if not pat.search(s): sys.exit(f'Marqueurs {nom} absents de {chemin}')
    n = pat.sub(lambda m: m.group(1) + '\n' + contenu + m.group(2), s)
    if n != s:
        open(chemin, 'w', encoding='utf-8').write(n); return True
    return False

def main():
    try:
        evts = recuperer()
    except Exception as e:
        print(f'Agenda indisponible ({e}) : rien à faire.'); return
    j = os.path.join(SITE, 'assets/agenda.json')
    ancien = json.load(open(j, encoding='utf-8')).get('evenements') if os.path.exists(j) else None
    if ancien != evts:
        with open(j, 'w', encoding='utf-8') as f:
            json.dump({'source': ORIGINE + 'agenda/', 'evenements': evts}, f, ensure_ascii=False, indent=1); f.write('\n')
    remplacer(os.path.join(SITE, 'agenda.html'), 'AGENDA', page_complete(evts))
    remplacer(os.path.join(SITE, 'index.html'), 'AGENDA-ACCUEIL', liste(evts[:MAX_ACCUEIL], '    ', 'Aucun rendez-vous annoncé pour le moment.'))
    print(f'{len(evts)} événements à venir.')

if __name__ == '__main__':
    main()
