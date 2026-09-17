import type { Context } from "@netlify/functions";
import { getDeployStore, getStore } from "@netlify/blobs";

type Campaign = {
  id: string;
  name: string;
  keywords: string[];
  message: string;
  url?: string;
  active: boolean;
  createdAt: string;
  updatedAt: string;
};

function getCampaignStore() {
  const production = Netlify.context?.deploy?.context === "production";
  return production
    ? getStore("crediti-automation", { consistency: "strong" })
    : getDeployStore("crediti-automation");
}

async function listCampaigns() {
  const store = getCampaignStore();
  const { blobs } = await store.list({ prefix: "campaigns/" });
  const items = await Promise.all(
    blobs.map(({ key }) => store.get(key, { type: "json" }) as Promise<Campaign | null>),
  );
  return items.filter(Boolean).sort((a, b) =>
    String(b?.updatedAt ?? "").localeCompare(String(a?.updatedAt ?? "")),
  );
}

function normalizeKeywords(value: unknown) {
  if (!Array.isArray(value)) return [];
  return [...new Set(value.map((item) => String(item).trim()).filter(Boolean))];
}

export default async (req: Request, _context: Context) => {
  const store = getCampaignStore();

  if (req.method === "GET") {
    return Response.json({ campaigns: await listCampaigns() });
  }

  if (req.method === "POST") {
    const body = await req.json().catch(() => null) as Partial<Campaign> | null;
    if (!body) return Response.json({ error: "Dados inválidos" }, { status: 400 });

    const name = String(body.name ?? "").trim();
    const keywords = normalizeKeywords(body.keywords);
    const message = String(body.message ?? "").trim();
    const url = String(body.url ?? "").trim();

    if (!name || keywords.length === 0 || !message) {
      return Response.json(
        { error: "Preencha nome, palavra-chave e mensagem" },
        { status: 400 },
      );
    }

    const now = new Date().toISOString();
    const id = body.id ? String(body.id) : crypto.randomUUID();
    const existing = await store.get(`campaigns/${id}`, { type: "json" }) as Campaign | null;

    const campaign: Campaign = {
      id,
      name,
      keywords,
      message,
      url: url || undefined,
      active: body.active !== false,
      createdAt: existing?.createdAt ?? now,
      updatedAt: now,
    };

    await store.setJSON(`campaigns/${id}`, campaign);
    return Response.json({ campaign });
  }

  if (req.method === "DELETE") {
    const url = new URL(req.url);
    const id = url.searchParams.get("id");
    if (!id) return Response.json({ error: "ID obrigatório" }, { status: 400 });
    await store.delete(`campaigns/${id}`);
    return Response.json({ success: true });
  }

  return new Response("Method not allowed", { status: 405 });
};
