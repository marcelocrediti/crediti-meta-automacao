import express from 'express';
import multer from 'multer';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const app = express();
const PORT = process.env.PORT || 10000;
const PUBLIC_DIR = path.join(__dirname, 'public');
const STORE_URL = process.env.PERSIST_API_URL || 'https://vgdtywdpywezrwlrsawq.supabase.co/functions/v1/meta-automation-store';
const storeKey = () => process.env.META_VERIFY_TOKEN || 'crediti-5k-webhook-2026';

function normalizeText(value = '') {
  return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
}
function absoluteUrl(req, value) {
  if (!value) return '';
  if (/^https?:\/\//i.test(value)) return value;
  const base = process.env.PUBLIC_BASE_URL || `${req.protocol}://${req.get('host')}`;
  return `${base.replace(/\/$/, '')}/${String(value).replace(/^\//, '')}`;
}
async function storeRequest(action='campaigns', options={}) {
  const url = `${STORE_URL}?action=${encodeURIComponent(action)}${options.id ? `&id=${encodeURIComponent(options.id)}` : ''}`;
  const response = await fetch(url, { method: options.method || 'GET', headers: { 'x-crediti-key': storeKey(), ...(options.headers || {}) }, body: options.body });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || `Armazenamento respondeu ${response.status}`);
  return data;
}
async function readCampaigns() {
  try { const data = await storeRequest('campaigns'); return Array.isArray(data.campaigns) ? data.campaigns : []; }
  catch (error) { console.error('STORE_READ_FAILED', error); return []; }
}

app.set('trust proxy', true);
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: true }));
app.use(express.static(PUBLIC_DIR, { etag: false, lastModified: false, setHeaders(res) { res.setHeader('Cache-Control', 'no-store, max-age=0'); } }));

app.get('/health', async (_req, res) => {
  const campaigns = await readCampaigns();
  res.json({ ok: true, service: 'Automação Crediti', platform: 'Render', persistentStorage: campaigns.length >= 0 });
});

app.get('/meta-oauth-callback', (req, res) => {
  const code = req.query.code;
  const error = req.query.error;
  const errorDescription = req.query.error_description;
  if (error) return res.status(400).send(`Falha na autorização da Meta: ${errorDescription || error}`);
  if (!code) return res.status(200).send('Callback da Crediti ativo no Render. Nenhum código de autorização recebido.');
  return res.status(200).send('Autorização recebida com sucesso. Pode voltar para a configuração da Crediti.');
});

app.get('/.netlify/functions/campaigns', async (_req, res) => {
  try { res.json({ campaigns: await readCampaigns() }); }
  catch (error) { res.status(500).json({ error: error.message }); }
});
app.post('/.netlify/functions/campaigns', async (req, res) => {
  try {
    const body = req.body || {};
    const name = String(body.name || '').trim();
    const keywords = Array.isArray(body.keywords) ? [...new Set(body.keywords.map(v => String(v).trim()).filter(Boolean))] : [];
    const message = String(body.message || '').trim();
    if (!name || !keywords.length || !message) return res.status(400).json({ error: 'Preencha nome, palavra-chave e mensagem' });
    const data = await storeRequest('campaigns', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ ...body, name, keywords, message }) });
    res.json(data);
  } catch (error) { res.status(500).json({ error: error.message }); }
});
app.delete('/.netlify/functions/campaigns', async (req, res) => {
  try { const id=String(req.query.id||''); if(!id) return res.status(400).json({error:'ID obrigatório'}); res.json(await storeRequest('campaigns',{method:'DELETE',id})); }
  catch(error){ res.status(500).json({error:error.message}); }
});

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 12 * 1024 * 1024 }, fileFilter: (_req, file, cb) => cb(null, file.mimetype === 'application/pdf' || String(file.originalname).toLowerCase().endsWith('.pdf')) });
app.post('/.netlify/functions/campaign-file', upload.single('file'), async (req, res) => {
  try {
    if (!req.file) return res.status(400).json({ error: 'Envie um arquivo PDF' });
    const form = new FormData();
    form.append('file', new Blob([req.file.buffer], { type: 'application/pdf' }), req.file.originalname);
    res.json(await storeRequest('file', { method: 'POST', body: form }));
  } catch (error) { res.status(500).json({ error: error.message }); }
});

function verifyWebhook(req, res) {
  const mode = req.query['hub.mode']; const token = req.query['hub.verify_token']; const challenge = req.query['hub.challenge'];
  const expected = process.env.META_VERIFY_TOKEN || 'crediti-5k-webhook-2026';
  if (mode === 'subscribe' && token === expected && challenge) return res.status(200).send(String(challenge));
  return res.status(200).send('Webhook da Crediti ativo no Render');
}
async function receiveWebhook(req, res) {
  res.status(200).send('EVENT_RECEIVED');
  const payload = req.body || {}; const campaigns = (await readCampaigns()).filter(c => c.active);
  for (const entry of payload.entry || []) for (const change of entry.changes || []) {
    const commentId = change?.value?.id; const commentText = change?.value?.text || '';
    if (change?.field !== 'comments' || !commentId || !commentText) continue;
    const text = normalizeText(commentText);
    const campaign = campaigns.find(c => (c.keywords || []).some(k => { const key=normalizeText(k); return key && (text===key || text.includes(key)); }));
    if (!campaign) continue;
    try {
      const accessToken = process.env.META_ACCESS_TOKEN; if (!accessToken) throw new Error('META_ACCESS_TOKEN não configurado');
      const graphBaseUrl = process.env.META_GRAPH_BASE_URL || 'https://graph.instagram.com'; const graphVersion = process.env.META_GRAPH_API_VERSION || 'v24.0';
      const campaignUrl = absoluteUrl(req, campaign.url || ''); const messageText = campaignUrl ? `${campaign.message}\n\n${campaignUrl}` : campaign.message;
      const messageEndpoint = new URL(`${graphBaseUrl}/${graphVersion}/me/messages`);
      messageEndpoint.searchParams.set('access_token', accessToken);
      const response = await fetch(messageEndpoint, { method:'POST', headers:{ 'content-type':'application/json' }, body:JSON.stringify({recipient:{comment_id:commentId},message:{text:messageText}}) });
      if (!response.ok) console.error('META_SEND_FAILED', response.status, await response.text());
    } catch (error) { console.error('META_AUTOMATION_FAILED', error); }
  }
}
app.get('/meta-webhook', verifyWebhook); app.post('/meta-webhook', receiveWebhook);
app.get('/.netlify/functions/meta-webhook', verifyWebhook); app.post('/.netlify/functions/meta-webhook', receiveWebhook);
app.use((_req, res) => res.sendFile(path.join(PUBLIC_DIR, 'index.html')));
app.listen(PORT, '0.0.0.0', () => console.log(`Automação Crediti online na porta ${PORT}`));
