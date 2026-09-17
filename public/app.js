const API='/.netlify/functions/campaigns';
const FILE_API='/.netlify/functions/campaign-file';
let campaigns=[];
const $=id=>document.getElementById(id);

function esc(v=''){
  return String(v).replace(/[&<>"']/g,c=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function toast(text){
  const el=$('toast');
  el.textContent=text;
  el.classList.add('show');
  setTimeout(()=>el.classList.remove('show'),2200);
}

async function load(){
  try{
    const r=await fetch(API,{cache:'no-store'});
    const d=await r.json();
    campaigns=d.campaigns||[];
    render();
  }catch{
    $('list').innerHTML='<div class="empty">Não foi possível carregar agora.</div>';
  }
}

function render(){
  const q=$('search').value.trim().toLowerCase();
  const filtered=campaigns.filter(c=>!q||c.name.toLowerCase().includes(q)||(c.keywords||[]).join(' ').toLowerCase().includes(q));
  $('total').textContent=campaigns.length;
  $('active').textContent=campaigns.filter(c=>c.active).length;
  $('paused').textContent=campaigns.filter(c=>!c.active).length;
  if(!filtered.length){
    $('list').innerHTML='<div class="empty">Nenhuma campanha encontrada.</div>';
    return;
  }
  $('list').innerHTML=filtered.map(c=>`
    <article class="card">
      <div class="row">
        <div class="title">${esc(c.name)}</div>
        <button class="switch ${c.active?'on':''}" data-action="toggle" data-id="${esc(c.id)}" aria-label="Ativar ou pausar"></button>
      </div>
      <div class="chips">${(c.keywords||[]).map(k=>`<span class="chip">${esc(k)}</span>`).join('')}</div>
      <div class="msg">${esc(c.message)}</div>
      ${c.url?`<div class="url">🔗 ${esc(c.url)}</div>`:''}
      <div class="actions">
        <button class="mini" data-action="edit" data-id="${esc(c.id)}">Editar</button>
        <button class="mini" data-action="duplicate" data-id="${esc(c.id)}">Duplicar</button>
        <button class="mini" data-action="delete" data-id="${esc(c.id)}">Excluir</button>
      </div>
    </article>`).join('');
}

function clearForm(){
  ['campaignId','name','keywords','message','url'].forEach(id=>$(id).value='');
  $('pdf').value='';
  $('fileStatus').textContent='Nenhum PDF selecionado.';
  $('activeInput').checked=true;
}

function openNew(){
  clearForm();
  $('formTitle').textContent='Nova campanha';
  $('modal').classList.add('show');
  document.documentElement.classList.add('locked');
}

function edit(id){
  const c=campaigns.find(x=>x.id===id);
  if(!c)return;
  clearForm();
  $('formTitle').textContent='Editar campanha';
  $('campaignId').value=c.id;
  $('name').value=c.name;
  $('keywords').value=(c.keywords||[]).join(', ');
  $('message').value=c.message;
  $('url').value=c.url||'';
  $('activeInput').checked=!!c.active;
  $('modal').classList.add('show');
  document.documentElement.classList.add('locked');
}

function closeModal(){
  $('modal').classList.remove('show');
  document.documentElement.classList.remove('locked');
}

async function uploadPdf(){
  const file=$('pdf').files[0];
  if(!file)return null;
  const fd=new FormData();
  fd.append('file',file);
  $('fileStatus').textContent='Enviando PDF...';
  const r=await fetch(FILE_API,{method:'POST',body:fd});
  const d=await r.json();
  if(!r.ok)throw new Error(d.error||'Falha no PDF');
  $('fileStatus').textContent='PDF enviado e link gerado.';
  return d.url;
}

async function saveCampaign(){
  const btn=$('saveBtn');
  btn.disabled=true;
  btn.textContent='Salvando...';
  try{
    let finalUrl=$('url').value.trim();
    const uploaded=await uploadPdf();
    if(uploaded)finalUrl=uploaded;
    const body={
      id:$('campaignId').value||undefined,
      name:$('name').value.trim(),
      keywords:$('keywords').value.split(',').map(x=>x.trim()).filter(Boolean),
      message:$('message').value.trim(),
      url:finalUrl,
      active:$('activeInput').checked
    };
    if(!body.name||!body.keywords.length||!body.message){
      throw new Error('Preencha nome, palavra-chave e mensagem');
    }
    const r=await fetch(API,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
    const d=await r.json();
    if(!r.ok)throw new Error(d.error||'Não foi possível salvar');
    closeModal();
    toast('Campanha salva');
    await load();
  }catch(e){
    toast(e.message||'Erro ao salvar');
  }finally{
    btn.disabled=false;
    btn.textContent='Salvar campanha';
  }
}

async function toggle(id){
  const c=campaigns.find(x=>x.id===id);
  if(!c)return;
  await fetch(API,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({...c,active:!c.active})});
  await load();
}

async function duplicate(id){
  const c=campaigns.find(x=>x.id===id);
  if(!c)return;
  await fetch(API,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({...c,id:undefined,name:c.name+' - cópia'})});
  toast('Campanha duplicada');
  await load();
}

async function removeCampaign(id){
  if(!confirm('Excluir esta campanha?'))return;
  await fetch(API+'?id='+encodeURIComponent(id),{method:'DELETE'});
  toast('Campanha excluída');
  await load();
}

document.addEventListener('click',e=>{
  const action=e.target.closest('[data-action]');
  if(action){
    const id=action.dataset.id;
    if(action.dataset.action==='toggle')toggle(id);
    if(action.dataset.action==='edit')edit(id);
    if(action.dataset.action==='duplicate')duplicate(id);
    if(action.dataset.action==='delete')removeCampaign(id);
  }
  if(e.target.id==='newCampaign' || e.target.id==='newCampaignFab')openNew();
  if(e.target.id==='cancelBtn')closeModal();
  if(e.target.id==='saveBtn')saveCampaign();
  if(e.target.id==='modal')closeModal();
});

$('search').addEventListener('input',render);
$('pdf').addEventListener('change',()=>{
  $('fileStatus').textContent=$('pdf').files[0]?`PDF selecionado: ${$('pdf').files[0].name}`:'Nenhum PDF selecionado.';
});

load();
