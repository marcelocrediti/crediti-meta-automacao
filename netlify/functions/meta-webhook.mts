import type { Context } from "@netlify/functions";

function normalizeText(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}

function matchesFiveK(value: string) {
  const text = normalizeText(value);
  const accepted = ["5k", "5 k", "5mil", "5 mil", "cinco mil"];
  return accepted.some((item) => text === item || text.includes(item));
}

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

function getMatchingComments(payload: MetaWebhookPayload) {
  const matches: Array<{ commentId: string; text: string }> = [];

  for (const entry of payload.entry ?? []) {
    for (const change of entry.changes ?? []) {
      const commentId = change.value?.id;
      const text = change.value?.text ?? "";

      if (change.field === "comments" && commentId && matchesFiveK(text)) {
        matches.push({ commentId, text });
      }
    }
  }

  return matches;
}

async function sendPrivateReply(commentId: string) {
  const accessToken = Netlify.env.get("META_ACCESS_TOKEN");
  const graphBaseUrl = Netlify.env.get("META_GRAPH_BASE_URL") ?? "https://graph.instagram.com";
  const graphVersion = Netlify.env.get("META_GRAPH_API_VERSION") ?? "v24.0";
  const pdfUrl =
    Netlify.env.get("META_5K_PDF_URL") ??
    "https://crediti-automacao.netlify.app/DESAFIO_DOS_R_5_MIL_CREDITI.pdf";

  if (!accessToken) {
    throw new Error("META_ACCESS_TOKEN não configurado");
  }

  const endpoint = `${graphBaseUrl}/${graphVersion}/${encodeURIComponent(commentId)}/private_replies`;
  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      authorization: `Bearer ${accessToken}`,
      "content-type": "application/json",
    },
    body: JSON.stringify({
      message:
        "Seu Desafio dos R$ 5 Mil chegou! 💛\n\n" +
        "A constância vale mais que a perfeição. Baixe o PDF e comece hoje:\n" +
        `${pdfUrl}\n\n@crediti.oficial`,
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
    const expected = Netlify.env.get("META_VERIFY_TOKEN");

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

  const comments = getMatchingComments(payload);

  for (const comment of comments) {
    try {
      const result = await sendPrivateReply(comment.commentId);
      console.log("CREDITI_5K_PRIVATE_REPLY_SENT", result);
    } catch (error) {
      console.error("CREDITI_5K_PRIVATE_REPLY_FAILED", error);
    }
  }

  return new Response("EVENT_RECEIVED", { status: 200 });
};
