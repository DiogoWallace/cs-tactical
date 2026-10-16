import { describe, expect, it, vi } from "vitest"

vi.mock("server-only", () => ({}))

const { buildServerHeaders } = await import("./server")

describe("buildServerHeaders", () => {
  it("forwards the user's cookies and identifies the frontend origin", () => {
    const headers = buildServerHeaders(
      "cs_tactical_session=abc; XSRF-TOKEN=def",
      "http://localhost:3000",
    )

    expect(headers.get("Cookie")).toBe("cs_tactical_session=abc; XSRF-TOKEN=def")
    expect(headers.get("Origin")).toBe("http://localhost:3000")
    expect(headers.get("Referer")).toBe("http://localhost:3000/")
    expect(headers.get("Accept")).toBe("application/json")
  })

  it("omits the Cookie header for anonymous requests", () => {
    expect(buildServerHeaders("", "http://localhost:3000").has("Cookie")).toBe(false)
  })
})
