(function(){
  document.querySelectorAll('form[data-confirmer]').forEach(function(f){
    f.addEventListener('submit',function(e){ if(!window.confirm(f.getAttribute('data-confirmer'))){ e.preventDefault(); } });
  });
})();
