"use client";

import { useState, Suspense } from "react";
import { createClient } from "@/infrastructure/supabase/client";
import { useSearchParams } from "next/navigation";

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const [email, setEmail] = useState("");
  const [status, setStatus] = useState<"idle" | "loading" | "sent" | "error">("idle");
  const [errorMsg, setErrorMsg] = useState("");
  const searchParams = useSearchParams();
  const redirect = searchParams.get("redirect") ?? "/vault";

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setStatus("loading");
    setErrorMsg("");

    const supabase = createClient();
    const { error } = await supabase.auth.signInWithOtp({
      email: email.trim().toLowerCase(),
      options: {
        emailRedirectTo: `${window.location.origin}/auth/callback?redirect=${encodeURIComponent(redirect)}`,
      },
    });

    if (error) {
      setStatus("error");
      setErrorMsg(error.message);
    } else {
      setStatus("sent");
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-neutral-50">
      <div className="w-full max-w-md mx-4">
        {/* Logo / Brand */}
        <div className="text-center mb-8">
          <h1 className="text-3xl font-bold text-neutral-900 tracking-tight">
            MediaVault
          </h1>
          <p className="text-neutral-500 mt-2">
            Accede a tu biblioteca de contenido
          </p>
        </div>

        {/* Card */}
        <div className="bg-white rounded-xl shadow-sm border border-neutral-200 p-8">
          {status === "sent" ? (
            <div className="text-center">
              <div className="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg className="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              </div>
              <h2 className="text-xl font-semibold text-neutral-900 mb-2">
                Revisa tu email
              </h2>
              <p className="text-neutral-600 text-sm">
                Enviamos un enlace de acceso a{" "}
                <span className="font-medium text-neutral-900">{email}</span>.
                <br />
                Haz clic en el enlace para entrar.
              </p>
              <button
                onClick={() => setStatus("idle")}
                className="mt-6 text-sm text-orange-600 hover:text-orange-700 font-medium"
              >
                Usar otro email
              </button>
            </div>
          ) : (
            <form onSubmit={handleSubmit}>
              <label
                htmlFor="email"
                className="block text-sm font-medium text-neutral-700 mb-2"
              >
                Email
              </label>
              <input
                id="email"
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="tu@email.com"
                className="w-full px-4 py-3 rounded-lg border border-neutral-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none transition text-neutral-900 placeholder:text-neutral-400"
                disabled={status === "loading"}
              />

              {errorMsg && (
                <p className="mt-2 text-sm text-red-600">{errorMsg}</p>
              )}

              <button
                type="submit"
                disabled={status === "loading"}
                className="w-full mt-4 py-3 px-4 bg-orange-600 hover:bg-orange-700 disabled:bg-orange-300 text-white font-medium rounded-lg transition"
              >
                {status === "loading" ? "Enviando..." : "Continuar con email"}
              </button>
            </form>
          )}
        </div>

        <p className="text-center text-xs text-neutral-400 mt-6">
          Te enviaremos un enlace seguro. Sin contraseña.
        </p>
      </div>
    </div>
  );
}
