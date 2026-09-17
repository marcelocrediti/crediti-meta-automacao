import express from 'express';
import multer from 'multer';
import fs from 'fs';
import path from 'path';
import crypto from 'crypto';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const app = express();
const PORT = process.env.PORT || 10000;
const PUBLIC_DIR = path.join(__dirname, 'public');
const DATA_DIR = path.join(__dirname, 'data');
const UPLOAD_DIR = path.join(DATA_DIR, 'uploads');
const CAMPAIGNS_FILE = path.join(DATA_DIR, 'campaigns.json');

fs.mkdirSync(UPLOAD_DIR, { recursive: true });

const seedCampaign = () => ({
  id: 'desafio-5k',
  name: 'Desafio 5K',
  keywords: ['5K', '5 K', '5MIL', '5 MIL', 'CINCO MIL'],
  message: 'Seu Desafio dos R$ 5 Mil chegou! 💛\n\nA constância vale mais que a perfeição. Baixe o PDF e comece hoje:',
  url: '/DESAFIO_DOS_R_5_MIL_CREDITI.pdf',
  active: true,
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
});

function readCampaigns() {
  try {
    if (!fs.existsSync(CAMPAIGNS_FILE)) {
      const initial = [seedCampaign()];
      fs.writeFileSync(CAMPAIGNS_FILE, JSON.stringify(initial, null, 2));
      return initial;
    }
    const data = JSON.parse(fs.readFileSync(CAMPAIGNS_FILE, 'utf8'));
    if (!Array.isArray(data) || data.length === 0) return [seedCampaign()];
    return data;
  } catch {
    return [seedCampaign()];
  }
}

function writeCampaigns(campaigns) {
  fs.writeFileSync(CAMPAIGNS_FILE, JSON.stringify(campaigns, null, 2));
}

function normalizeText(value = '') {
  return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
}

function absoluteUrl(req, value) {
  if (!value) return '';
  if (/^https?:\/\//i.test(value)) return value;
  const base = process.env.PUBLIC_BASE_URL || `${req.protocol}://${req.get('host')}`;
  return `${base.replace(/\/$/, '')}/${String(value).replace(/^\//, '')}`;
}

app.set('trust proxy', true);
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: true }));
app.use('/uploads', express.static(UPLOAD_DIR, { maxAge: '1h' }));
app.use(express.static(PUBLIC_DIR, { etag: false, lastModified: false, setHeaders(res) { res.setHeader('Cache-Control', 'no-store, max-age=0'); } }));

app.get('/health', (_req, res) => res.json({ ok: true, service: 'Automação Crediti' }));

app.get('/.netlify/functions/campaigns', (_req, res) => {
  const campaigns = readCampaigns().sort((a, b) => String(b.updatedAt || '').localeCompare(String(a.updatedAt || '')));
  res.json({ campaigns });
});

app.post('/.netlify/functions/campaigns', (req, res) => {
  const body = req.body || {};
  const name = String(body.name || '').trim();
  const keywords = Array.isArray(body.keywords) ? [...new Set(body.keywords.map(v => String(v).trim()).filter(Boolean))] : [];
  const message = String(body.message || '').trim();
  const url = String(body.url || '').trim();
  if (!name || !keywords.length || !message) return res.status(400).json({ error: 'Preencha nome, palavra-chave e mensagem' });

  const campaigns = readCampaigns();
  const now = new Date().toISOString();
  const id = body.id ? String(body.id) : crypto.randomUUID();
  const idx = campaigns.findIndex(c => c.id === id);
  const existing = idx >= 0 ? campaigns[idx] : null;
  const campaign = {
    id,
    name,
    keywords,
    message,
    url: url || undefined,
    active: body.active !== false,
    createdAt: existing?.createdAt || now,
    updatedAt: now,
  };
  if (idx >= 0) campaigns[idx] = campaign; else campaigns.push(campaign);
  writeCampaigns(campaigns);
  res.json({ campaign });
});

app.delete('/.netlify/functions/campaigns', (req, res) => {
  const id = String(req.query.id || '');
  if (!id) return res.status(400).json({ error: 'ID obrigatório' });
  writeCampaigns(readCampaigns().filter(c => c.id !== id));
  res.json({ success: true });
});

const upload = multer({
  storage: multer.diskStorage({
    destination: (_req, _file, cb) => cb(null, UPLOAD_DIR),
    filename: (_req, file, cb) => cb(null, `${Date.now()}-${crypto.randomUUID()}${path.extname(file.originalname || '.pdf') || '.pdf'}`),
  }),
  limits: { fileSize: 12 * 1024 * 1024 },
  fileFilter: (_req, file, cb) => cb(null, file.mimetype === 'application/pdf' || String(file.originalname).toLowerCase().endsWith('.pdf')),
});

app.post('/.netlify/functions/campaign-file', upload.single('file'), (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'Envie um arquivo PDF' });
  const url = absoluteUrl(req, `/uploads/${req.file.filename}`);
  res.json({ success: true, url, name: req.file.originalname });
});

app.get('/.netlify/functions/meta-webhook', (req, res) => {
  const mode = req.query['hub.mode'];
  const token = req.query['hub.verify_token'];
  const challenge = req.query['hub.challenge'];
  const expected = process.env.META_VERIFY_TOKEN || 'crediti-5k-webhook-2026';
  if (mode === 'subscribe' && token === expected && challenge) return res.status(200).send(String(challenge));
  res.status(200).send('Webhook da Crediti ativo');
});

app.post('/.netlify/functions/meta-webhook', async (req, res) => {
  res.status(200).send('EVENT_RECEIVED');
  const payload = req.body || {};
  const campaigns = readCampaigns().filter(c => c.active);
  for (const entry of payload.entry || []) {
    for (const change of entry.changes || []) {
      const commentId = change?.value?.id;
      const commentText = change?.value?.text || '';
      if (change?.field !== 'comments' || !commentId || !commentText) continue;
      const text = normalizeText(commentText);
      const campaign = campaigns.find(c => (c.keywords || []).some(k => {
        const key = normalizeText(k);
        return key && (text === key || text.includes(key));
      }));
      if (!campaign) continue;
      try {
        const accessToken = process.env.META_ACCESS_TOKEN;
        if (!accessToken) throw new Error('META_ACCESS_TOKEN não configurado');
        const graphBaseUrl = process.env.META_GRAPH_BASE_URL || 'https://graph.instagram.com';
        const graphVersion = process.env.META_GRAPH_API_VERSION || 'v24.0';
        const campaignUrl = absoluteUrl(req, campaign.url || '');
        const messageText = campaignUrl ? `${campaign.message}\n\n${campaignUrl}` : campaign.message;
        const response = await fetch(`${graphBaseUrl}/${graphVersion}/me/messages`, {
          method: 'POST',
          headers: { authorization: `Bearer ${accessToken}`, 'content-type': 'application/json' },
          body: JSON.stringify({ recipient: { comment_id: commentId }, message: { text: messageText } }),
        });
        if (!response.ok) console.error('META_SEND_FAILED', response.status, await response.text());
      } catch (error) {
        console.error('META_AUTOMATION_FAILED', error);
      }
    }
  }
});

app.use((_req, res) => res.sendFile(path.join(PUBLIC_DIR, 'index.html')));

app.listen(PORT, '0.0.0.0', () => console.log(`Automação Crediti online na porta ${PORT}`));
