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

type MetaCommentChange = {
  field?: string;
  value?: {
    id?: string;
    text?: string;
  };
};

type MetaWebhookPayload = {
  entry?: Array<{
    changes?: MetaCommentChange[];
  }>;
};

function getCampaignStore() {
  const production = Netlify.context?.deploy?.context === "production";
  return production
    ? getStore("crediti-automation", { consistency: "strong" })
    : getDeployStore("crediti-automation");
}

function normalizeText(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

async function getActiveCampaigns() {
  const store = getCampaignStore();
  const { blobs } = await store.list({ prefix: "campaigns/" });
  const campaigns = await Promise.all(
    blobs.map(({ key }) => store.get(key, { type: "json" }) as Promise<Campaign | null>),
  );
  return campaigns.filter((campaign): campaign is Campaign => Boolean(campaign?.active));
}

function campaignMatches(campaign: Campaign, comment: string) {
  const text = normalizeText(comment);
  return campaign.keywords.some((keyword) => {
    const normalizedKeyword = normalizeText(keyword);
    return normalizedKeyword && (text === normalizedKeyword || text.includes(normalizedKeyword));
  });
}

async function sendPrivateReply(commentId: string, campaign: Campaign) {
  const accessToken = Netlify.env.get("META_ACCESS_TOKEN");
  const graphBaseUrl = Netlify.env.get("META_GRAPH_BASE_URL") ?? "https://graph.instagram.com";
  const graphVersion = Netlify.env.get("META_GRAPH_API_VERSION") ?? "v24.0";

  if (!accessToken) throw new Error("META_ACCESS_TOKEN não configurado");

  const text = campaign.url
    ? `${campaign.message}\n\n${campaign.url}`
    : campaign.message;

  const endpoint = `${graphBaseUrl}/${graphVersion}/me/messages`;
  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      authorization: `Bearer ${accessToken}`,
      "content-type": "application/json",
    },
    body: JSON.stringify({
      recipient: { comment_id: commentId },
      message: { text },
    }),
  });

  const responseBody = await response.text();
  if (!response.ok) {
    throw new Error(`Meta respondeu ${response.status}: ${responseBody}`);
  }

  return responseBody;
}

export default async (req: Request, _context: Context) => {
  const url = new URL(req.url);

  if (req.method === "GET") {
    const mode = url.searchParams.get("hub.mode");
    const token = url.searchParams.get("hub.verify_token");
    const challenge = url.searchParams.get("hub.challenge");
    const expected = Netlify.env.get("META_VERIFY_TOKEN") ?? "crediti-5k-webhook-2026";

    if (mode === "subscribe" && token && expected && token === expected && challenge) {
      return new Response(challenge, { status: 200 });
    }

    return new Response("Webhook da Crediti ativo", { status: 200 });
  }

  if (req.method !== "POST") {
    return new Response("Method not allowed", { status: 405 });
  }

  let payload: MetaWebhookPayload;
  try {
    payload = (await req.json()) as MetaWebhookPayload;
  } catch {
    return new Response("Invalid JSON", { status: 400 });
  }

  console.log("META_WEBHOOK_EVENT", JSON.stringify(payload));
  const campaigns = await getActiveCampaigns();

  for (const entry of payload.entry ?? []) {
    for (const change of entry.changes ?? []) {
      const commentId = change.value?.id;
      const commentText = change.value?.text ?? "";
      if (change.field !== "comments" || !commentId || !commentText) continue;

      const campaign = campaigns.find((item) => campaignMatches(item, commentText));
      if (!campaign) continue;

      try {
        const result = await sendPrivateReply(commentId, campaign);
        console.log("CREDITI_AUTOMATION_SENT", campaign.id, result);
      } catch (error) {
        console.error("CREDITI_AUTOMATION_FAILED", campaign.id, error);
      }
    }
  }

  return new Response("EVENT_RECEIVED", { status: 200 });
};
