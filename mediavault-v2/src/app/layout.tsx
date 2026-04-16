import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
});

export const metadata: Metadata = {
  title: "MediaVault — JetPack Store",
  description: "Biblioteca de contenido multimedia premium",
  robots: { index: false, follow: false }, // Private app, no indexing
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es" className={`${inter.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col font-sans bg-neutral-50 text-neutral-900">
        {children}
      </body>
    </html>
  );
}
