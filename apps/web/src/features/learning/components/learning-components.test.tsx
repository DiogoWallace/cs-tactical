import { fireEvent, render, screen, waitFor, within } from "@testing-library/react"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { ApiError } from "@/lib/api/errors"
import { CourseOverview } from "@/features/academy/components/course-overview"
import { course } from "@/features/academy/test/fixtures"

import type { LearningLoad } from "../api/progress"
import { completeLesson, enrollInCourse, InvalidLearningResponse } from "../api/learning"
import { enrolled, enrollmentSummary, notEnrolled } from "../test/fixtures"
import { CompleteLessonCard } from "./complete-lesson-card"
import { CourseLearningProvider } from "./course-learning-provider"
import { EnrollmentBadge } from "./enrollment-badge"
import { EnrollmentPanel } from "./enrollment-panel"
import { LessonStatusMark } from "./lesson-status-mark"
import { MyProgress } from "./my-progress"

vi.mock("../api/learning", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api/learning")>()),
  enrollInCourse: vi.fn(),
  completeLesson: vi.fn(),
}))

const COURSE = "fundamentos-cs2"

/** Página do curso como montada em app/(marketing)/academy/cursos/[course]/page.tsx. */
function renderCoursePage(initial: LearningLoad) {
  return render(
    <CourseLearningProvider course={COURSE} initial={initial}>
      <CourseOverview
        course={course()}
        learningPanel={<EnrollmentPanel />}
        renderLessonStatus={(lesson) => <LessonStatusMark lesson={lesson} />}
      />
    </CourseLearningProvider>,
  )
}

function renderLesson(initial: LearningLoad, lesson = "counter-strafe") {
  return render(
    <CourseLearningProvider course={COURSE} initial={initial}>
      <CompleteLessonCard lesson={lesson} />
      <ul>
        {["counter-strafe", "peek-seguro", "crosshair"].map((slug) => (
          <li key={slug} data-testid={`item-${slug}`}>
            <LessonStatusMark lesson={slug} />
          </li>
        ))}
      </ul>
    </CourseLearningProvider>,
  )
}

beforeEach(() => {
  vi.mocked(enrollInCourse).mockReset()
  vi.mocked(completeLesson).mockReset()
})

describe("EnrollmentPanel", () => {
  it("invites visitors to sign in, coming back to the course, and keeps the content open", () => {
    renderCoursePage({ kind: "anonymous" })

    expect(screen.getByRole("link", { name: "Entrar para se matricular" })).toHaveAttribute(
      "href",
      `/login?next=${encodeURIComponent(`/academy/cursos/${COURSE}`)}`,
    )
    expect(screen.getByRole("link", { name: "Começar pela primeira aula" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Matricular/ })).not.toBeInTheDocument()
  })

  it("enrolls a signed-in player and shows the real progress returned by the API", async () => {
    vi.mocked(enrollInCourse).mockResolvedValue(enrolled())
    renderCoursePage({ kind: "player", progress: notEnrolled() })

    fireEvent.click(screen.getByRole("button", { name: "Matricular-se no curso" }))
    expect(screen.getByRole("button", { name: "Matriculando..." })).toBeDisabled()

    await screen.findByRole("progressbar", { name: "Progresso do curso" })
    expect(enrollInCourse).toHaveBeenCalledWith(COURSE)
    expect(screen.getByText("0 de 3 aulas concluídas")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /Continuar: Counter-strafe/ })).toHaveAttribute(
      "href",
      `/academy/cursos/${COURSE}/aulas/counter-strafe`,
    )
    expect(
      screen.getByText("Matrícula feita. Seu progresso neste curso será salvo."),
    ).toBeInTheDocument()
  })

  it("keeps the button and explains the failure when enrolling fails", async () => {
    vi.mocked(enrollInCourse).mockRejectedValue(
      new ApiError(429, { message: "x", code: "too_many_requests" }),
    )
    renderCoursePage({ kind: "player", progress: notEnrolled() })

    fireEvent.click(screen.getByRole("button", { name: "Matricular-se no curso" }))

    expect(await screen.findByRole("alert")).toHaveTextContent("Aguarde um minuto")
    expect(await screen.findByRole("button", { name: "Matricular-se no curso" })).toBeEnabled()
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument()
  })

  it("shows progress, completed marks and where to continue for an enrolled player", () => {
    renderCoursePage({ kind: "player", progress: enrolled(["counter-strafe"]) })

    const bar = screen.getByRole("progressbar", { name: "Progresso do curso" })
    expect(bar).toHaveAttribute("aria-valuetext", "33,33%, 1 de 3 aulas concluídas")
    expect(screen.getByRole("link", { name: /Continuar: Peek seguro/ })).toBeInTheDocument()
    expect(screen.getAllByTestId("lesson-completed")).toHaveLength(1)
    const first = screen.getByRole("link", { name: /Counter-strafe/ })
    expect(within(first).getByText("Concluída")).toBeInTheDocument()
  })

  it("celebrates a completed course and points to lessons published afterwards", () => {
    renderCoursePage({
      kind: "player",
      progress: enrolled(["counter-strafe", "peek-seguro"], {
        completed_at: "2026-11-03T10:00:00Z",
        new_lessons_available: true,
      }),
    })

    expect(screen.getByText("Curso concluído")).toBeInTheDocument()
    expect(screen.getByText(/novas aulas disponíveis/)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /Continuar: Crosshair/ })).toBeInTheDocument()
  })

  it("says when the progress could not be loaded, without hiding the course", () => {
    renderCoursePage({ kind: "unavailable" })

    expect(screen.getByText(/Não foi possível carregar seu progresso/)).toBeInTheDocument()
    expect(
      screen.getByRole("heading", { level: 1, name: "Fundamentos do CS2" }),
    ).toBeInTheDocument()
  })
})

describe("CompleteLessonCard", () => {
  it("invites visitors to sign in and come back to the lesson", () => {
    renderLesson({ kind: "anonymous" })

    expect(screen.getByRole("link", { name: "Entrar" })).toHaveAttribute(
      "href",
      `/login?next=${encodeURIComponent(`/academy/cursos/${COURSE}/aulas/counter-strafe`)}`,
    )
    expect(screen.queryByRole("button")).not.toBeInTheDocument()
  })

  it("completes the lesson (enrolling if needed), updates the whole page and announces it", async () => {
    vi.mocked(completeLesson).mockResolvedValue(enrolled(["counter-strafe"]))
    renderLesson({ kind: "player", progress: notEnrolled() })
    expect(screen.getByText(/também faz sua matrícula/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole("button", { name: "Marcar aula como concluída" }))

    expect(await screen.findByText("Aula concluída")).toBeInTheDocument()
    expect(completeLesson).toHaveBeenCalledWith(COURSE, "counter-strafe")
    expect(screen.getByText("Aula concluída. Progresso do curso: 33,33%.")).toBeInTheDocument()
    expect(
      within(screen.getByTestId("item-counter-strafe")).getByText("Concluída"),
    ).toBeInTheDocument()
    expect(
      within(screen.getByTestId("item-peek-seguro")).queryByText("Concluída"),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Marcar aula como concluída" }),
    ).not.toBeInTheDocument()
  })

  it("shows an already completed lesson and flags content updated since then", () => {
    const progress = enrolled(["counter-strafe"])
    progress.lessons[0] = { ...progress.lessons[0]!, updated_since_completion: true }
    renderLesson({ kind: "player", progress })

    expect(screen.getByText("Aula concluída")).toBeInTheDocument()
    expect(screen.getByText(/atualizado depois que você concluiu/)).toBeInTheDocument()
    expect(
      within(screen.getByTestId("item-counter-strafe")).getByText("atualizada"),
    ).toBeInTheDocument()
  })

  it("explains failures and keeps the lesson not completed", async () => {
    vi.mocked(completeLesson).mockRejectedValue(
      new ApiError(404, { message: "Not found.", code: "not_found" }),
    )
    renderLesson({ kind: "player", progress: enrolled() })

    fireEvent.click(screen.getByRole("button", { name: "Marcar aula como concluída" }))

    expect(await screen.findByRole("alert")).toHaveTextContent("não está mais disponível")
    expect(await screen.findByRole("button", { name: "Marcar aula como concluída" })).toBeEnabled()
    expect(screen.queryByTestId("lesson-completed")).not.toBeInTheDocument()
  })

  it("treats a response outside the contract as a failure, without inventing progress", async () => {
    vi.mocked(completeLesson).mockRejectedValue(new InvalidLearningResponse())
    renderLesson({ kind: "player", progress: enrolled() })

    fireEvent.click(screen.getByRole("button", { name: "Marcar aula como concluída" }))

    await waitFor(() =>
      expect(screen.getByRole("alert")).toHaveTextContent("Não foi possível salvar"),
    )
    expect(screen.queryByText("Aula concluída")).not.toBeInTheDocument()
  })
})

describe("LessonStatusMark and EnrollmentBadge", () => {
  it("renders nothing outside a course provider", () => {
    const { container } = render(<LessonStatusMark lesson="counter-strafe" />)
    expect(container).toBeEmptyDOMElement()
  })

  it("marks catalog cards as enrolled (with percent) or completed", () => {
    const { rerender } = render(<EnrollmentBadge enrollment={enrollmentSummary()} />)
    expect(screen.getByText("Matriculado · 33,33%")).toBeInTheDocument()

    rerender(
      <EnrollmentBadge enrollment={enrollmentSummary({ completed_at: "2026-11-03T10:00:00Z" })} />,
    )
    expect(screen.getByText("Curso concluído")).toBeInTheDocument()
  })
})

describe("MyProgress", () => {
  it("invites the player to the catalog when there is no enrollment", () => {
    render(<MyProgress enrollments={[]} timeZone="America/Sao_Paulo" />)

    expect(screen.getByText("Você ainda não está em nenhum curso")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Ver cursos" })).toHaveAttribute("href", "/academy")
  })

  it("lists active courses with progress and continue, and archived ones as history only", () => {
    render(
      <MyProgress
        timeZone="America/Sao_Paulo"
        enrollments={[
          enrollmentSummary(),
          enrollmentSummary({
            course: { slug: "economia-antiga", title: "Economia antiga", status: "archived" },
            completed_at: "2026-10-01T02:00:00Z",
            progress: { completed_lessons: 4, total_lessons: null, percent: null },
            next_lesson: null,
          }),
        ]}
      />,
    )

    const active = screen.getByRole("region", { name: "Meus cursos" })
    expect(within(active).getByRole("link", { name: "Fundamentos do CS2" })).toHaveAttribute(
      "href",
      "/academy/cursos/fundamentos-cs2",
    )
    expect(within(active).getByRole("progressbar")).toHaveAttribute(
      "aria-valuetext",
      "33,33%, 1 de 3 aulas concluídas",
    )
    expect(within(active).getByRole("link", { name: /Continuar: Peek seguro/ })).toBeInTheDocument()

    const history = screen.getByRole("region", { name: "Histórico" })
    expect(within(history).getByText("Economia antiga")).toBeInTheDocument()
    expect(within(history).queryByRole("link")).not.toBeInTheDocument()
    // Data no fuso do jogador: 02:00 UTC é 30/09 em São Paulo.
    expect(
      within(history).getByText(/4 aulas concluídas · curso concluído em 30 de set\. de 2026/),
    ).toBeInTheDocument()
  })
})
