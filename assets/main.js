(function(){
  var b=document.querySelector('.nav-toggle'),n=document.getElementById('nav');
  if(b&&n){
    b.addEventListener('click',function(){var o=n.classList.toggle('open');b.setAttribute('aria-expanded',o?'true':'false');});
    n.addEventListener('click',function(e){if(e.target.tagName==='A'){n.classList.remove('open');b.setAttribute('aria-expanded','false');}});
  }
  // Agenda : masque les événements déjà terminés entre deux synchronisations
  var now=new Date(),evts=document.querySelectorAll('.evt[data-fin]');
  for(var i=0;i<evts.length;i++){var f=evts[i].getAttribute('data-fin').replace(' ','T');var d=new Date(f);if(!isNaN(d)&&d<now){evts[i].hidden=true;}}
  // Vidéos des séances : le lecteur youtube-nocookie.com n'est chargé qu'au clic (sans JavaScript : lien YouTube)
  var fa=document.querySelectorAll('.video-facade[data-yt]');
  for(var j=0;j<fa.length;j++){fa[j].addEventListener('click',function(ev){
    var id=this.getAttribute('data-yt');if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;
    ev.preventDefault();
    var f=document.createElement('iframe');
    f.src='https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1&rel=0&playsinline=1';
    f.title=this.getAttribute('data-titre')||'Vidéo de la séance';
    f.allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
    f.setAttribute('allowfullscreen','');f.referrerPolicy='strict-origin-when-cross-origin';
    this.parentNode.replaceChild(f,this);f.focus();
  });}
  var y=document.getElementById('annee'); if(y){y.textContent=new Date().getFullYear();}
})();
