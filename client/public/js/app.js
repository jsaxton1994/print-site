document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-flash] button').forEach(button=>button.addEventListener('click',()=>button.closest('[data-flash]').remove()));
  const input=document.querySelector('#documents');
  if(input){input.addEventListener('change',()=>{const zone=input.closest('.upload-zone');const label=zone?.querySelector('strong');if(label)label.textContent=input.files.length?`${input.files.length} file${input.files.length===1?'':'s'} selected`:'Choose documents to upload';});}
  document.querySelectorAll('a[href="#new-job"],a[href="#create-user"],a[href="#create-template"]').forEach(link=>link.addEventListener('click',()=>{document.body.classList.add('modal-open');}));
  document.querySelectorAll('.modal-close').forEach(link=>link.addEventListener('click',()=>document.body.classList.remove('modal-open')));
});
