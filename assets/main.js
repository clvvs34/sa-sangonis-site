(function(){
  var b=document.querySelector('.nav-toggle'),n=document.getElementById('nav');
  if(b&&n){
    b.addEventListener('click',function(){var o=n.classList.toggle('open');b.setAttribute('aria-expanded',o?'true':'false');});
    n.addEventListener('click',function(e){if(e.target.tagName==='A'){n.classList.remove('open');b.setAttribute('aria-expanded','false');}});
  }
  // Agenda : masque les événements déjà terminés entre deux synchronisations
  var now=new Date(),evts=document.querySelectorAll('.evt[data-fin]');
  for(var i=0;i<evts.length;i++){var f=evts[i].getAttribute('data-fin').replace(' ','T');var d=new Date(f);if(!isNaN(d)&&d<now){evts[i].hidden=true;}}
  var y=document.getElementById('annee'); if(y){y.textContent=new Date().getFullYear();}
})();
