import { describe, expect, it } from "vitest"

import { cn } from "./utils"

describe("cn", () => {
  it("joins conditional classes", () => {
    expect(cn("px-2", false && "hidden", "text-sm")).toBe("px-2 text-sm")
  })

  it("lets later Tailwind classes win conflicts", () => {
    expect(cn("px-2 py-1", "px-4")).toBe("py-1 px-4")
  })
})
