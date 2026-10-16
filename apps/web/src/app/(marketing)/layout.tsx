import { SiteHeader } from "@/components/site-header"

/** Páginas públicas: início e status da plataforma. */
export default function MarketingLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <SiteHeader />
      <main className="flex flex-1 flex-col">{children}</main>
    </>
  )
}
