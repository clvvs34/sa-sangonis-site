/* Signalements citoyens — carte et formulaire (sans traceur, tuiles OpenStreetMap). */
(function () {
  'use strict';
  if (!window.L) return;

  var CENTRE = [43.6487, 3.5022];
  // Zone acceptée (large) autour de la commune — identique au contrôle côté serveur.
  var ZONE = L.latLngBounds([43.600, 3.455], [43.700, 3.556]);
  var LIMITE_CARTE = L.latLngBounds([43.585, 3.425], [43.715, 3.585]);
  var MAX_COTE = 1600, QUALITE = 0.8;

  var $ = function (id) { return document.getElementById(id); };
  var form = $('form-signal'), msg = $('form-message'), etat = $('position-etat');

  /* ---------- Carte ---------- */
  var carte = L.map('carte', {
    center: CENTRE, zoom: 14, minZoom: 12, maxZoom: 19,
    maxBounds: LIMITE_CARTE, maxBoundsViscosity: 1.0, scrollWheelZoom: false, tap: true
  });
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>'
  }).addTo(carte);
  carte.attributionControl.setPrefix('<a href="https://leafletjs.com">Leaflet</a>');
  carte.on('focus', function () { carte.scrollWheelZoom.enable(); });
  carte.on('blur', function () { carte.scrollWheelZoom.disable(); });

  if (window.SANGONIS_CONTOUR) {
    var contour = L.polygon(window.SANGONIS_CONTOUR, {
      color: '#9b2326', weight: 2, dashArray: '6 6', fill: true, fillColor: '#ead9a5', fillOpacity: 0.12, interactive: false
    }).addTo(carte);
    carte.fitBounds(contour.getBounds(), { padding: [10, 10] });
  }

  function pin(classe, titre) {
    return L.divIcon({
      className: 'repere ' + classe,
      html: '<svg viewBox="0 0 30 42" width="30" height="42" aria-hidden="true"><path d="M15 1C7.3 1 1 7.2 1 14.9 1 25.3 15 41 15 41s14-15.7 14-26.1C29 7.2 22.7 1 15 1z"/><circle cx="15" cy="15" r="5.2"/></svg>',
      iconSize: [30, 42], iconAnchor: [15, 41], popupAnchor: [0, -36]
    });
  }

  /* ---------- Signalements publiés ---------- */
  var couche = L.layerGroup().addTo(carte);
  function dateFr(iso) {
    var p = String(iso).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
  }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  function popup(s) {
    var d = el('div', 'popup-signal');
    d.appendChild(el('p', 'popup-cat', s.categorie_libelle));
    var meta = el('p', 'popup-meta');
    meta.appendChild(el('span', 'statut statut--' + s.statut, s.statut_libelle));
    meta.appendChild(document.createTextNode(' · signalé le ' + dateFr(s.date)));
    d.appendChild(meta);
    if (s.photo) {
      var a = el('a', 'popup-photo'); a.href = s.photo; a.target = '_blank'; a.rel = 'noopener';
      var img = el('img'); img.src = s.photo; img.alt = 'Photo du signalement'; img.loading = 'lazy';
      a.appendChild(img); d.appendChild(a);
    }
    if (s.commentaire) d.appendChild(el('p', 'popup-com', s.commentaire));
    return d;
  }
  function chargerPublies() {
    fetch('api/signalements.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        couche.clearLayers();
        var liste = (data && data.signalements) || [];
        liste.forEach(function (s) {
          L.marker([s.lat, s.lng], { icon: pin('repere--' + s.statut), title: s.categorie_libelle, keyboard: true })
            .bindPopup(popup(s), { maxWidth: 260, minWidth: 200 }).addTo(couche);
        });
        var enCours = liste.filter(function (s) { return s.statut !== 'resolu'; }).length;
        $('carte-compte').textContent = liste.length
          ? liste.length + ' signalement' + (liste.length > 1 ? 's' : '') + ' publié' + (liste.length > 1 ? 's' : '') + ', dont ' + enCours + ' en cours.'
          : 'Aucun signalement publié pour le moment.';
      })
      .catch(function () { $('carte-compte').textContent = 'Les signalements publiés n’ont pas pu être chargés.'; });
  }
  chargerPublies();

  /* ---------- Emplacement du nouveau signalement ---------- */
  var repere = null;
  function poser(latlng, source, precision) {
    if (!ZONE.contains(latlng)) {
      etat.className = 'position-etat position-etat--erreur';
      etat.textContent = 'Cet endroit est en dehors de Saint-André-de-Sangonis. Placez le repère dans la commune.';
      if (repere) { carte.removeLayer(repere); repere = null; }
      $('lat').value = ''; $('lng').value = '';
      return false;
    }
    if (!repere) {
      repere = L.marker(latlng, { icon: pin('repere--nouveau'), draggable: true, autoPan: true, title: 'Emplacement de votre signalement', zIndexOffset: 1000 }).addTo(carte);
      repere.on('dragend', function () { poser(repere.getLatLng(), 'deplace'); });
    } else {
      repere.setLatLng(latlng);
    }
    $('lat').value = latlng.lat.toFixed(6);
    $('lng').value = latlng.lng.toFixed(6);
    etat.className = 'position-etat position-etat--ok';
    var txt = source === 'gps' ? 'Position trouvée' + (precision ? ' (précision ± ' + Math.round(precision) + ' m)' : '') + '.'
            : source === 'deplace' ? 'Repère déplacé.' : 'Emplacement choisi sur la carte.';
    etat.textContent = txt + ' Vous pouvez faire glisser le repère pour l’ajuster.';
    return true;
  }
  carte.on('click', function (e) { poser(e.latlng, 'carte'); });

  var btnLoc = $('btn-localiser');
  btnLoc.addEventListener('click', function () {
    if (!('geolocation' in navigator)) {
      etat.textContent = 'La géolocalisation n’est pas disponible : touchez la carte à l’endroit du problème.';
      return;
    }
    btnLoc.disabled = true;
    etat.className = 'position-etat';
    etat.textContent = 'Recherche de votre position…';
    navigator.geolocation.getCurrentPosition(function (pos) {
      btnLoc.disabled = false;
      var ll = L.latLng(pos.coords.latitude, pos.coords.longitude);
      if (poser(ll, 'gps', pos.coords.accuracy)) carte.setView(ll, 18);
    }, function (err) {
      btnLoc.disabled = false;
      etat.className = 'position-etat position-etat--erreur';
      etat.textContent = (err && err.code === 1
        ? 'Vous avez refusé la géolocalisation.'
        : 'Votre position n’a pas pu être déterminée.') + ' Touchez la carte à l’endroit du problème.';
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });

  /* ---------- Photo : redimensionnement dans le navigateur ---------- */
  var photoBlob = null, photoUrl = null;
  function redimensionner(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, MAX_COTE / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width = Math.round(w * k); c.height = Math.round(h * k);
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        // Le ré-encodage supprime aussi les métadonnées (EXIF, position GPS de l’appareil).
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', QUALITE);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function choisirPhoto(input) {
    var f = input.files && input.files[0];
    input.value = '';
    if (!f) return;
    if (!/^image\//.test(f.type) && f.type !== '') { afficherMessage('Ce fichier n’est pas une image.'); return; }
    redimensionner(f).then(function (b) {
      if (b.size > 8 * 1024 * 1024) { afficherMessage('Photo trop lourde (8 Mo maximum).'); return; }
      photoBlob = b;
      if (photoUrl) URL.revokeObjectURL(photoUrl);
      photoUrl = URL.createObjectURL(b);
      $('photo-img').src = photoUrl;
      $('photo-apercu').hidden = false;
      masquerMessage();
    });
  }
  $('photo-camera').addEventListener('change', function () { choisirPhoto(this); });
  $('photo-galerie').addEventListener('change', function () { choisirPhoto(this); });
  $('photo-retirer').addEventListener('click', function () {
    photoBlob = null;
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = null;
    $('photo-img').removeAttribute('src');
    $('photo-apercu').hidden = true;
  });

  var com = $('commentaire');
  com.addEventListener('input', function () { $('compteur').textContent = com.value.length + ' / 1000'; });

  /* ---------- Jeton anti-robot ---------- */
  function chargerJeton() {
    return fetch('api/signalement.php?jeton=1', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) { $('jeton').value = (d && d.jeton) || ''; })
      .catch(function () {});
  }
  chargerJeton();

  /* ---------- Envoi ---------- */
  function afficherMessage(t) { msg.textContent = t; msg.hidden = false; }
  function masquerMessage() { msg.hidden = true; msg.textContent = ''; }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    masquerMessage();
    var erreurs = [];
    if (!$('lat').value) erreurs.push('Indiquez l’emplacement du problème (bouton « Me localiser » ou touchez la carte).');
    if (!$('categorie').value) erreurs.push('Choisissez une catégorie.');
    if (!photoBlob && !com.value.trim()) erreurs.push('Ajoutez une photo ou un commentaire (ou les deux).');
    var mail = $('email');
    if (mail.value && !mail.checkValidity()) erreurs.push('L’adresse e-mail n’est pas valide.');
    if (erreurs.length) { afficherMessage(erreurs.join(' ')); return; }

    var fd = new FormData(form);
    if (photoBlob) fd.append('photo', photoBlob, 'photo.jpg');
    var btn = $('btn-envoyer');
    btn.disabled = true; btn.textContent = 'Envoi en cours…';
    fetch('api/signalement.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Erreur du serveur (' + r.status + '). Réessayez plus tard.' }; }); })
      .then(function (d) {
        if (d && d.ok) {
          form.hidden = true;
          $('form-succes-texte').textContent = d.message;
          $('form-succes').hidden = false;
          $('form-succes').focus();
        } else {
          afficherMessage((d && d.message) || 'L’envoi a échoué. Réessayez.');
        }
      })
      .catch(function () { afficherMessage('Connexion impossible. Vérifiez votre réseau et réessayez.'); })
      .then(function () { btn.disabled = false; btn.textContent = 'Envoyer le signalement'; });
  });

  $('btn-nouveau').addEventListener('click', function () {
    form.reset();
    $('photo-retirer').click();
    $('compteur').textContent = '0 / 1000';
    if (repere) { carte.removeLayer(repere); repere = null; }
    $('lat').value = ''; $('lng').value = '';
    etat.className = 'position-etat';
    etat.textContent = 'Aucun emplacement choisi pour l’instant.';
    $('form-succes').hidden = true;
    form.hidden = false;
    chargerJeton();
  });
})();
