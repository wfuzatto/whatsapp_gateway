document.addEventListener('click', async (event) => {
  const copy = event.target.closest('[data-copy]');
  if (copy) { try { await navigator.clipboard.writeText(copy.dataset.copy); const old=copy.textContent; copy.textContent='Copiado'; setTimeout(()=>copy.textContent=old,1600); } catch { copy.textContent='Selecione e copie'; } }
  const dismiss = event.target.closest('[data-dismiss] button'); if (dismiss) dismiss.parentElement.remove();
  const menu = event.target.closest('[data-menu]'); if (menu) document.getElementById('sidebar')?.classList.toggle('open');
  const opener = event.target.closest('[data-open]'); if(opener) document.getElementById(opener.dataset.open)?.scrollIntoView({behavior:'smooth',block:'center'});
  const emoji = event.target.closest('[data-emoji]'); if(emoji){const box=document.querySelector('.composer textarea'); if(box){box.value+=' 🙂';box.focus();}}
});
document.querySelectorAll('[data-filter]').forEach(input=>input.addEventListener('input',()=>{const table=document.getElementById(input.dataset.filter);const needle=input.value.toLocaleLowerCase('pt-BR');table?.querySelectorAll('tbody tr').forEach(row=>row.hidden=!row.innerText.toLocaleLowerCase('pt-BR').includes(needle));}));
const stream=document.getElementById('message-stream');if(stream)stream.scrollTop=stream.scrollHeight;
document.querySelectorAll('[data-attachment-input]').forEach(input=>input.addEventListener('change',()=>{const output=document.querySelector('[data-attachment-name]');if(output)output.textContent=input.files?.[0]?.name||'';}));
document.querySelectorAll('[data-quick-replies]').forEach(select=>select.addEventListener('change',()=>{const body=select.selectedOptions[0]?.dataset.body;const area=select.closest('form')?.querySelector('textarea[name="body"]');if(body&&area){area.value=area.value?area.value+'\n'+body:body;area.dispatchEvent(new Event('input'));area.focus();}select.value='';}));
const inbox=document.querySelector('.inbox-layout');if(inbox){let filter='all';const items=[...inbox.querySelectorAll('.conversation-item')];const search=inbox.querySelector('[data-inbox-search]');const update=()=>{const needle=(search?.value||'').toLocaleLowerCase('pt-BR');items.forEach(item=>{const matchesFilter=filter==='all'||(filter==='unread'&&item.dataset.unread==='1')||(filter==='assigned'&&item.dataset.assigned==='1');const matchesSearch=(item.innerText+' '+(item.dataset.search||'')).toLocaleLowerCase('pt-BR').includes(needle);item.hidden=!(matchesFilter&&matchesSearch);});};inbox.querySelectorAll('[data-inbox-filter]').forEach(button=>button.addEventListener('click',()=>{filter=button.dataset.inboxFilter;inbox.querySelectorAll('[data-inbox-filter]').forEach(tab=>tab.classList.toggle('selected',tab===button));update();}));search?.addEventListener('input',update);}
document.querySelectorAll('.composer textarea').forEach(area=>{area.addEventListener('input',()=>{area.style.height='auto';area.style.height=Math.min(area.scrollHeight,120)+'px';});area.addEventListener('keydown',ev=>{if(ev.key==='Enter'&&!ev.shiftKey){ev.preventDefault();area.form.requestSubmit();}});});
if(new URLSearchParams(location.search).get('r')==='inbox'){setInterval(()=>{const active=document.activeElement;const composing=active&&(active.matches('textarea,input')||active.closest('.composer'));if(document.visibilityState==='visible'&&!composing)location.reload();},12000);}
document.querySelectorAll('[data-totp-qr]').forEach(container=>{
  const uri=container.dataset.totpQr||'';
  if(!uri||typeof window.qrcode!=='function'){
    container.textContent='Não foi possível carregar o gerador do QR code. Use a chave secreta abaixo para configurar manualmente.';
    return;
  }
  try{
    const qr=window.qrcode(0,'M');
    qr.addData(uri,'Byte');
    qr.make();
    container.innerHTML=qr.createImgTag(5,4,'QR code da autenticação em duas etapas');
    const image=container.querySelector('img');
    if(image){image.alt='QR code para configurar a autenticação em duas etapas';image.className='mfa-qr-image';}
  }catch(error){
    container.textContent='Não foi possível gerar o QR code. Use a chave secreta abaixo para configurar manualmente.';
  }
});
