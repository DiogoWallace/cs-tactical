import { fireEvent, render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { SHA } from "../../test/fixtures"
import { CalloutBlock } from "./callout-block"
import { ImageBlock } from "./image-block"
import { LessonBlocks } from "./lesson-blocks"
import { QuizRefBlock } from "./quiz-ref-block"
import { VideoBlock, youtubeEmbedUrl } from "./video-block"

const imageData = {
  url: `/api/v1/academy/media/${SHA}.png`,
  alt: "Gráfico da velocidade",
  caption: "Velocidade ao longo do tempo.",
  width: 1280,
  height: 720,
}

describe("CalloutBlock", () => {
  it.each([
    ["info", "Informação"],
    ["tip", "Dica"],
    ["warning", "Atenção"],
  ] as const)("names the %s variant in text, not only by color", (variant, label) => {
    render(<CalloutBlock data={{ variant, title: null, text: "Use **D**." }} />)

    const note = screen.getByRole("note", { name: label })
    expect(note).toHaveTextContent(label)
    expect(within(note).getByText("D").tagName).toBe("STRONG")
  })

  it("uses the title as the accessible name when there is one", () => {
    render(
      <CalloutBlock data={{ variant: "warning", title: "Cuidado", text: "Não pare no meio." }} />,
    )
    expect(screen.getByRole("note", { name: "Cuidado" })).toHaveTextContent("Não pare no meio.")
  })
})

describe("ImageBlock", () => {
  it("renders the API URL with alt text, dimensions, lazy loading and caption", () => {
    render(<ImageBlock data={imageData} />)

    const image = screen.getByRole("img", { name: "Gráfico da velocidade" })
    expect(image).toHaveAttribute("src", imageData.url)
    expect(image).toHaveAttribute("width", "1280")
    expect(image).toHaveAttribute("height", "720")
    expect(image).toHaveAttribute("loading", "lazy")
    expect(screen.getByText("Velocidade ao longo do tempo.").tagName).toBe("FIGCAPTION")
  })

  it("hides decorative images from assistive technology", () => {
    const { container } = render(<ImageBlock data={{ ...imageData, alt: "", caption: null }} />)

    expect(screen.queryByRole("img")).toBeNull()
    expect(container.querySelector("img")).toHaveAttribute("alt", "")
  })

  it("shows a notice with the alt text when the image fails to load", () => {
    const { container } = render(<ImageBlock data={imageData} />)

    fireEvent.error(container.querySelector("img")!)

    expect(container.querySelector("img")).toBeNull()
    expect(
      screen.getByRole("img", { name: "Imagem indisponível: Gráfico da velocidade" }),
    ).toBeInTheDocument()
    expect(screen.getByText("Não foi possível carregar a imagem.")).toBeInTheDocument()
  })
})

describe("VideoBlock", () => {
  const data = {
    provider: "youtube" as const,
    video_id: "AbCdEfGhIjK",
    title: "Demonstração",
    start_seconds: 12,
  }

  it("contacts nobody until the player is requested", () => {
    const { container } = render(<VideoBlock data={data} />)

    expect(container.querySelector("iframe")).toBeNull()
    expect(
      screen.getByRole("button", { name: "Assistir ao vídeo: Demonstração" }),
    ).toBeInTheDocument()
  })

  it("loads the privacy-enhanced player with a title, sandbox and referrer policy", () => {
    render(<VideoBlock data={data} />)

    fireEvent.click(screen.getByRole("button", { name: "Assistir ao vídeo: Demonstração" }))

    const frame = screen.getByTitle("Demonstração")
    expect(frame.tagName).toBe("IFRAME")
    expect(frame).toHaveAttribute(
      "src",
      "https://www.youtube-nocookie.com/embed/AbCdEfGhIjK?autoplay=1&rel=0&start=12",
    )
    expect(frame).toHaveAttribute("sandbox", "allow-scripts allow-same-origin allow-presentation")
    expect(frame).toHaveAttribute("referrerpolicy", "strict-origin-when-cross-origin")
    expect(frame).toHaveAttribute("loading", "lazy")
  })

  it("builds embed URLs only from valid ids", () => {
    expect(youtubeEmbedUrl("AbCdEfGhIjK", null)).toBe(
      "https://www.youtube-nocookie.com/embed/AbCdEfGhIjK?autoplay=1&rel=0",
    )
    expect(youtubeEmbedUrl("../../evil", null)).toBeNull()
    expect(youtubeEmbedUrl('x" onload="alert(1)', null)).toBeNull()
  })

  it("renders nothing for an invalid id", () => {
    const { container } = render(<VideoBlock data={{ ...data, video_id: "not-an-id" }} />)
    expect(container).toBeEmptyDOMElement()
  })
})

describe("QuizRefBlock", () => {
  it("shows only the public numbers and no way to answer yet", () => {
    render(
      <QuizRefBlock
        data={{
          quiz: "counter-strafe-quiz",
          title: "Teste rápido",
          question_count: 4,
          max_attempts: 3,
          pass_percent: 70,
        }}
      />,
    )

    const quiz = screen.getByRole("region", { name: "Teste rápido" })
    expect(quiz).toHaveTextContent("Quiz da aula")
    expect(quiz).toHaveTextContent("4 perguntas")
    expect(quiz).toHaveTextContent("Aprovação com 70%")
    expect(quiz).toHaveTextContent("3 tentativas")
    expect(within(quiz).queryByRole("button")).toBeNull()
    expect(within(quiz).queryByRole("radio")).toBeNull()
    expect(within(quiz).queryByRole("checkbox")).toBeNull()
  })

  it("uses the singular for one question and one attempt", () => {
    render(
      <QuizRefBlock
        data={{ quiz: "q", title: "Q", question_count: 1, max_attempts: 1, pass_percent: 100 }}
      />,
    )
    expect(screen.getByText("1 pergunta")).toBeInTheDocument()
    expect(screen.getByText("1 tentativa")).toBeInTheDocument()
  })
})

describe("LessonBlocks", () => {
  it("renders each block type with its own renderer, in order", () => {
    const { container } = render(
      <LessonBlocks
        blocks={[
          { type: "markdown", data: { text: "## Começo" } },
          { type: "callout", data: { variant: "tip", title: null, text: "Dica rápida." } },
          { type: "image", data: imageData },
          {
            type: "video",
            data: {
              provider: "youtube",
              video_id: "AbCdEfGhIjK",
              title: "Vídeo",
              start_seconds: null,
            },
          },
          {
            type: "quiz_ref",
            data: {
              quiz: "q",
              title: "Quiz",
              question_count: 2,
              max_attempts: 3,
              pass_percent: 70,
            },
          },
        ]}
      />,
    )

    expect(
      Array.from(container.querySelectorAll("[data-block]"), (node) =>
        node.getAttribute("data-block"),
      ),
    ).toEqual(["markdown", "callout", "image", "video", "quiz_ref"])
    expect(screen.getByRole("heading", { level: 2, name: "Começo" })).toBeInTheDocument()
    expect(screen.getByRole("note", { name: "Dica" })).toBeInTheDocument()
    expect(screen.getByRole("img", { name: "Gráfico da velocidade" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Assistir ao vídeo: Vídeo" })).toBeInTheDocument()
    expect(screen.getByRole("region", { name: "Quiz" })).toBeInTheDocument()
  })
})
