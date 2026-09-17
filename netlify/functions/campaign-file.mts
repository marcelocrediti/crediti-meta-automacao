import type { Context } from "@netlify/functions";
import { getDeployStore, getStore } from "@netlify/blobs";

function getFileStore() {
  const production = Netlify.context?.deploy?.context === "production";
  return production
    ? getStore("crediti-campaign-files", { consistency: "strong" })
    : getDeployStore("crediti-campaign-files");
}

function safeName(value: string) {
  return value.replace(/[^a-zA-Z0-9._-]+/g, "-").replace(/-+/g, "-").slice(0, 120) || "arquivo.pdf";
}

export default async (req: Request, _context: Context) => {
  const store = getFileStore();
  const url = new URL(req.url);

  if (req.method === "POST") {
    const form = await req.formData().catch(() => null);
    const file = form?.get("file");
    if (!(file instanceof File)) {
      return Response.json({ error: "Selecione um PDF" }, { status: 400 });
    }

    const isPdf = file.type === "application/pdf" || file.name.toLowerCase().endsWith(".pdf");
    if (!isPdf) {
      return Response.json({ error: "Envie somente arquivo PDF" }, { status: 400 });
    }

    const maxBytes = 5 * 1024 * 1024;
    if (file.size > maxBytes) {
      return Response.json({ error: "O PDF deve ter no máximo 5 MB" }, { status: 400 });
    }

    const id = crypto.randomUUID();
    const key = `files/${id}`;
    const filename = safeName(file.name);
    await store.set(key, await file.arrayBuffer(), {
      metadata: {
        filename,
        contentType: "application/pdf",
        createdAt: new Date().toISOString(),
      },
    });

    const publicUrl = `${url.origin}/.netlify/functions/campaign-file?id=${encodeURIComponent(id)}`;
    return Response.json({ id, filename, url: publicUrl });
  }

  if (req.method === "GET") {
    const id = url.searchParams.get("id");
    if (!id) return new Response("Arquivo não informado", { status: 400 });

    const result = await store.getWithMetadata(`files/${id}`, { type: "arrayBuffer" });
    if (!result?.data) return new Response("Arquivo não encontrado", { status: 404 });

    const filename = String(result.metadata?.filename ?? "arquivo.pdf");
    return new Response(result.data, {
      status: 200,
      headers: {
        "content-type": "application/pdf",
        "content-disposition": `inline; filename="${filename}"`,
        "cache-control": "public, max-age=3600",
      },
    });
  }

  return new Response("Method not allowed", { status: 405 });
};
