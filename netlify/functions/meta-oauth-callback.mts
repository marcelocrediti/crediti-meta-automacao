import type { Context } from "@netlify/functions";

export default async (req: Request, _context: Context) => {
  const url = new URL(req.url);
  const code = url.searchParams.get("code");
  const error = url.searchParams.get("error");
  const errorDescription = url.searchParams.get("error_description");

  if (error) {
    return new Response(`Falha na autorização da Meta: ${errorDescription ?? error}`, {
      status: 400,
      headers: { "content-type": "text/plain; charset=utf-8" },
    });
  }

  if (!code) {
    return new Response("Callback da Crediti ativo. Nenhum código de autorização recebido.", {
      status: 200,
      headers: { "content-type": "text/plain; charset=utf-8" },
    });
  }

  return new Response("Autorização recebida com sucesso. Pode voltar para a configuração da Crediti.", {
    status: 200,
    headers: { "content-type": "text/plain; charset=utf-8" },
  });
};
