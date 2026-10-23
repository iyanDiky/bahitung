export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public details: Record<string, string[]> = {},
  ) {
    super(message);
  }
}
export async function csrf() {
  const response = await fetch("/sanctum/csrf-cookie", {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
  if (!response.ok)
    throw new ApiError(response.status, "Koneksi belum siap. Coba lagi.");
}
export async function api<T>(
  path: string,
  method = "GET",
  body?: unknown,
  idempotencyKey?: string,
): Promise<T> {
  if (!path.startsWith("/api/v1/")) throw new Error("Alamat API tidak valid.");
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
  if (method !== "GET") {
    if (!document.cookie.split("; ").some((v) => v.startsWith("XSRF-TOKEN=")))
      await csrf();
    const cookie = document.cookie
      .split("; ")
      .find((v) => v.startsWith("XSRF-TOKEN="));
    if (cookie)
      headers["X-XSRF-TOKEN"] = decodeURIComponent(
        cookie.slice("XSRF-TOKEN=".length),
      );
    headers["Content-Type"] = "application/json";
  }
  let response: Response;
  try {
    response = await fetch(path, {
      method,
      credentials: "include",
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(
      0,
      "Tidak dapat terhubung. Periksa koneksi lalu coba lagi.",
    );
  }
  const json = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401)
      window.dispatchEvent(new Event("bahitung:unauthenticated"));
    if (response.status === 419) {
      await csrf();
      throw new ApiError(
        419,
        "Sesi formulir kedaluwarsa. Silakan kirim ulang.",
      );
    }
    throw new ApiError(
      response.status,
      json.error?.message || "Permintaan gagal. Silakan coba lagi.",
      json.error?.details || {},
    );
  }
  return json as T;
}
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError)
    return Object.values(error.details).flat().join(" ") || error.message;
  return "Terjadi gangguan koneksi. Silakan coba lagi.";
}
export function safeNext(value: unknown): string {
  return typeof value === "string" &&
    ([
      "/dashboard",
      "/dompet",
      "/transaksi",
      "/budget",
      "/target",
      "/analisis",
      "/anggota",
      "/pengaturan",
      "/keluarga",
    ].includes(value) ||
      value.startsWith("/undangan?") ||
      value.startsWith("/verifikasi-email?"))
    ? value
    : "/dashboard";
}
