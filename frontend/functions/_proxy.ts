// Cloudflare Pages Function Proxy Helper
// Reenvía peticiones a Render conservando cookies, CSRF y cabeceras de proxy.

type PagesContext = {
  request: Request;
  env: {
    BACKEND_URL?: string;
  };
  next: () => Promise<Response>;
};

export async function handleProxy(context: PagesContext): Promise<Response> {
  const url = new URL(context.request.url);
  const backendBase = context.env.BACKEND_URL;

  if (!backendBase) {
    return new Response(
      JSON.stringify({
        error: "CONFIGURATION_ERROR",
        message: "BACKEND_URL environment variable is not defined on Cloudflare Pages.",
      }),
      {
        status: 500,
        headers: { "Content-Type": "application/json" },
      }
    );
  }

  // Construir la URL hacia el backend en Render
  const targetUrl = new URL(url.pathname + url.search, backendBase);

  // Copiar cabeceras y añadir cabeceras estándar de proxy reverso
  const forwardHeaders = new Headers(context.request.headers);
  forwardHeaders.set("Host", targetUrl.hostname);
  forwardHeaders.set("X-Forwarded-Host", url.host);
  forwardHeaders.set("X-Forwarded-Proto", url.protocol.replace(":", ""));

  const isBodyAllowed = !["GET", "HEAD"].includes(context.request.method);
  
  // Realizar la petición al backend en Render
  const backendResponse = await fetch(targetUrl.toString(), {
    method: context.request.method,
    headers: forwardHeaders,
    body: isBodyAllowed ? context.request.body : undefined,
    redirect: "manual",
  });

  const responseHeaders = new Headers(backendResponse.headers);

  // Sanitizar Set-Cookie: eliminar el atributo Domain para que el navegador
  // guarde las cookies como 'Host-Only' en el dominio de Cloudflare Pages.
  // Usar getSetCookie() si está disponible para evitar colapso de múltiples cookies.
  const rawSetCookies = backendResponse.headers.getSetCookie 
    ? backendResponse.headers.getSetCookie() 
    : [backendResponse.headers.get("set-cookie")].filter(Boolean) as string[];

  responseHeaders.delete("set-cookie");

  for (const cookieStr of rawSetCookies) {
    const sanitized = cookieStr
      .replace(/Domain=[^;]+;?\s*/gi, "")
      .replace(/;\s*$/, "");
    responseHeaders.append("set-cookie", sanitized);
  }

  return new Response(backendResponse.body, {
    status: backendResponse.status,
    statusText: backendResponse.statusText,
    headers: responseHeaders,
  });
}
