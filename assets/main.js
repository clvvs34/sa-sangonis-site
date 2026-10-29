(function(){
  var b=document.querySelector('.nav-toggle'),n=document.getElementById('nav');
  if(b&&n){
    b.addEventListener('click',function(){var o=n.classList.toggle('open');b.setAttribute('aria-expanded',o?'true':'false');});
    n.addEventListener('click',function(e){if(e.target.tagName==='A'){n.classList.remove('open');b.setAttribute('aria-expanded','false');}});
  }
  var y=document.getElementById('annee'); if(y){y.textContent=new Date().getFullYear();}
})();
