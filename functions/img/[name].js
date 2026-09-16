// 更新ページからアップロードした画像を KV から配信 /img/<name>
export async function onRequestGet({ env, params }) {
  const name = params.name;
  if (!/^[\w.-]+$/.test(name)) return new Response("Not found", { status: 404 });
  const { value, metadata } = await env.IMG.getWithMetadata(name, { type: "arrayBuffer" });
  if (!value) return new Response("Not found", { status: 404 });
  return new Response(value, { headers: { "content-type": (metadata && metadata.ct) || "application/octet-stream", "cache-control": "public, max-age=31536000, immutable" } });
}
