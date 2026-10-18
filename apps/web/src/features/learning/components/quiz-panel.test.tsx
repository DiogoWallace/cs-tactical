import { fireEvent, render, screen, waitFor, within } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"

import type { QuizAttemptsLoad } from "../api/progress"
import {
  fetchCourseProgress,
  fetchQuizAttempt,
  fetchQuizAttempts,
  startQuizAttempt,
  submitQuizAttempt,
} from "../api/learning"
import type { LearningLoad } from "../api/progress"
import {
  attemptSummary,
  enrolled,
  openAttempt,
  Q1,
  Q1_D,
  Q2,
  Q2_PEEK,
  resultWithoutReview,
  resultWithReview,
} from "../test/fixtures"
import { CourseLearningProvider } from "./course-learning-provider"
import { QuizPanel, type QuizInfo } from "./quiz-panel"

vi.mock("../api/learning", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api/learning")>()),
  startQuizAttempt: vi.fn(),
  submitQuizAttempt: vi.fn(),
  fetchQuizAttempt: vi.fn(),
  fetchQuizAttempts: vi.fn(),
  fetchCourseProgress: vi.fn(),
}))

const QUIZ: QuizInfo = {
  quiz: "counter-strafe-quiz",
  title: "Teste rápido",
  question_count: 2,
  max_attempts: 3,
  pass_percent: 70,
}

function renderPanel(
  attempts: QuizAttemptsLoad,
  learning: LearningLoad = { kind: "player", progress: enrolled() },
) {
  return render(
    <CourseLearningProvider course="fundamentos-cs2" initial={learning}>
      <QuizPanel quiz={QUIZ} lesson="counter-strafe" initial={attempts} />
    </CourseLearningProvider>,
  )
}

beforeEach(() => {
  vi.mocked(startQuizAttempt).mockReset()
  vi.mocked(submitQuizAttempt).mockReset()
  vi.mocked(fetchQuizAttempt).mockReset()
  vi.mocked(fetchQuizAttempts).mockReset().mockResolvedValue([])
  vi.mocked(fetchCourseProgress).mockReset().mockResolvedValue(enrolled())
})

describe("QuizPanel", () => {
  it("invites visitors to sign in and come back to the lesson, without questions", () => {
    renderPanel({ kind: "anonymous" }, { kind: "anonymous" })

    expect(screen.getByRole("link", { name: "Entrar para responder" })).toHaveAttribute(
      "href",
      `/login?next=${encodeURIComponent("/academy/cursos/fundamentos-cs2/aulas/counter-strafe")}`,
    )
    expect(screen.queryByRole("group")).not.toBeInTheDocument()
  })

  it("starts an attempt and shows each question as a labelled group of options", async () => {
    vi.mocked(startQuizAttempt).mockResolvedValue(openAttempt())
    renderPanel({ kind: "player", attempts: [] })
    expect(screen.getByText("3 tentativas restantes")).toBeInTheDocument()

    fireEvent.click(screen.getByRole("button", { name: "Iniciar quiz" }))

    const first = await screen.findByRole("group", { name: /Pergunta 1:\s*Indo para a esquerda/ })
    expect(within(first).getAllByRole("radio")).toHaveLength(2)
    expect(
      within(screen.getByRole("group", { name: /Pergunta 2:\s*Quando usar/ })).getAllByRole(
        "checkbox",
      ),
    ).toHaveLength(2)
    expect(screen.getByText("Tentativa 1 de 3")).toBeInTheDocument()
    expect(startQuizAttempt).toHaveBeenCalledWith("counter-strafe-quiz")
  })

  it("sends only the chosen options and shows the server result without the answer key", async () => {
    vi.mocked(startQuizAttempt).mockResolvedValue(openAttempt())
    vi.mocked(submitQuizAttempt).mockResolvedValue(resultWithoutReview())
    renderPanel({ kind: "player", attempts: [] })
    fireEvent.click(screen.getByRole("button", { name: "Iniciar quiz" }))
    await screen.findByRole("group", { name: /Pergunta 1/ })

    const send = screen.getByRole("button", { name: "Enviar respostas" })
    expect(send).toBeDisabled()
    fireEvent.click(screen.getByRole("radio", { name: "D" }))
    fireEvent.click(screen.getByRole("checkbox", { name: "No peek" }))
    expect(send).toBeEnabled()
    fireEvent.click(send)

    expect(
      await screen.findByRole("heading", { name: "Resultado da tentativa 1" }),
    ).toBeInTheDocument()
    expect(submitQuizAttempt).toHaveBeenCalledWith("019a2c00-0000-7000-8000-000000000001", [
      { question_id: Q1, option_ids: [Q1_D] },
      { question_id: Q2, option_ids: [Q2_PEEK] },
    ])
    expect(screen.getByText("50%")).toBeInTheDocument()
    expect(screen.getByText("Não aprovado")).toBeInTheDocument()
    expect(screen.getByText(/Pergunta 1, certa/)).toBeInTheDocument()
    expect(screen.getByText(/Pergunta 2, errada/)).toBeInTheDocument()
    expect(screen.queryByText("Correta")).not.toBeInTheDocument()
    expect(screen.queryByText(/Explicação/)).not.toBeInTheDocument()
    expect(screen.getByText(/O gabarito e as explicações aparecem quando/)).toBeInTheDocument()
    expect(screen.getByText("Você acertou 1 de 2 (50%). Não aprovado.")).toBeInTheDocument()
    await waitFor(() => expect(fetchQuizAttempts).toHaveBeenCalled())
    expect(fetchCourseProgress).toHaveBeenCalledWith("fundamentos-cs2")
  })

  it("shows correct options and explanations only when the API releases the review", async () => {
    vi.mocked(startQuizAttempt).mockResolvedValue(openAttempt())
    vi.mocked(submitQuizAttempt).mockResolvedValue(resultWithReview())
    renderPanel({ kind: "player", attempts: [] })
    fireEvent.click(screen.getByRole("button", { name: "Iniciar quiz" }))
    await screen.findByRole("group", { name: /Pergunta 1/ })
    fireEvent.click(screen.getByRole("radio", { name: "D" }))
    fireEvent.click(screen.getByRole("checkbox", { name: "No peek" }))
    fireEvent.click(screen.getByRole("button", { name: "Enviar respostas" }))

    expect(await screen.findByText("Aprovado")).toBeInTheDocument()
    expect(screen.getAllByText("Correta")).toHaveLength(2)
    expect(screen.getByText("A tecla oposta zera a velocidade.")).toBeInTheDocument()
    expect(
      screen.queryByText(/O gabarito e as explicações aparecem quando/),
    ).not.toBeInTheDocument()
  })

  it("offers to continue an open attempt", () => {
    renderPanel({
      kind: "player",
      attempts: [
        attemptSummary({
          status: "in_progress",
          submitted_at: null,
          score_percent: null,
          passed: null,
        } as never),
      ],
    })

    expect(screen.getByRole("button", { name: "Continuar tentativa" })).toBeInTheDocument()
  })

  it("shows an approval and opens its review on demand", async () => {
    vi.mocked(fetchQuizAttempt).mockResolvedValue(resultWithReview())
    renderPanel({
      kind: "player",
      attempts: [attemptSummary({ score_percent: 100, passed: true, review_available: true })],
    })

    expect(screen.getByText(/Você foi aprovado neste quiz com 100%/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Tentar de novo|Iniciar/ })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole("button", { name: "Ver revisão" }))

    expect(await screen.findByText("A tecla oposta zera a velocidade.")).toBeInTheDocument()
  })

  it("explains when the attempts are over", () => {
    renderPanel({
      kind: "player",
      attempts: [1, 2, 3].map((number) =>
        attemptSummary({ attempt_number: number, attempts_remaining: 0, review_available: true }),
      ),
    })

    expect(screen.getByText(/Suas tentativas acabaram/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Tentar de novo|Iniciar/ })).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Ver revisão" })).toBeInTheDocument()
  })

  it("explains API refusals in player terms and reloads the attempts", async () => {
    vi.mocked(startQuizAttempt).mockRejectedValue(
      new ApiError(409, { message: "x", code: "quiz_attempts_exhausted" }),
    )
    renderPanel({ kind: "player", attempts: [attemptSummary()] })

    fireEvent.click(screen.getByRole("button", { name: "Tentar de novo" }))

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Você não tem mais tentativas neste quiz.",
    )
    expect(fetchQuizAttempts).toHaveBeenCalledWith("counter-strafe-quiz")
  })

  it("says when the quiz state could not be loaded", () => {
    renderPanel({ kind: "unavailable" })

    expect(screen.getByText(/Não foi possível carregar o quiz agora/)).toBeInTheDocument()
    expect(screen.queryByRole("button")).not.toBeInTheDocument()
  })
})
