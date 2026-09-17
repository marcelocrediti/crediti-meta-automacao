import type { Context } from "@netlify/functions";

export default async (req: Request, _context: Context) => {
  if (req.method !== "GET") {
    return new Response("Method not allowed", { status: 405 });
  }

  const url = new URL(req.url);
  const key = url.searchParams.get("key");
  const expected = Netlify.env.get("META_VERIFY_TOKEN") ?? "crediti-5k-webhook-2026";

  if (!key || key !== expected) {
    return new Response("Unauthorized", { status: 401 });
  }

  const accessToken = Netlify.env.get("META_ACCESS_TOKEN");
  const graphBaseUrl = Netlify.env.get("META_GRAPH_BASE_URL") ?? "https://graph.instagram.com";
  const graphVersion = Netlify.env.get("META_GRAPH_API_VERSION") ?? "v24.0";
  const instagramAccountId = Netlify.env.get("META_INSTAGRAM_ACCOUNT_ID") ?? "17841477632398320";

  if (!accessToken) {
    return new Response("META_ACCESS_TOKEN não configurado", { status: 500 });
  }

  const endpoint = new URL(`${graphBaseUrl}/${graphVersion}/${instagramAccountId}/subscribed_apps`);
  endpoint.searchParams.set("subscribed_fields", "comments,messages,messaging_postbacks");
  endpoint.searchParams.set("access_token", accessToken);

  const response = await fetch(endpoint, { method: "POST" });
  const body = await response.text();

  console.log("META_SUBSCRIBE_RESPONSE", response.status, body);

  return new Response(body, {
    status: response.status,
    headers: { "content-type": "application/json; charset=utf-8" },
  });
};
