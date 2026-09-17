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

  let payload: unknown;
  try {
    payload = await req.json();
  } catch {
    return new Response("Invalid JSON", { status: 400 });
  }

  console.log("META_WEBHOOK_EVENT", JSON.stringify(payload));

  // Nesta primeira etapa, recebemos e registramos os eventos.
  // O envio da DM será ativado assim que o token/permissões da conta forem conectados.
  const body = JSON.stringify(payload);
  if (matchesFiveK(body)) {
    console.log("CREDITI_5K_KEYWORD_MATCH");
  }

  return new Response("EVENT_RECEIVED", { status: 200 });
};
