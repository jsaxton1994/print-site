document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-flash] button').forEach(button=>button.addEventListener('click',()=>button.closest('[data-flash]').remove()));
  document.querySelectorAll('.modal-close').forEach(link=>link.addEventListener('click',()=>document.body.classList.remove('modal-open')));
});
