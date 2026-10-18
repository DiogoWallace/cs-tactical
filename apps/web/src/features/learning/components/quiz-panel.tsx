"use client"

import { useState, useTransition } from "react"
import Link from "next/link"
import { CheckCircle2Icon, LogInIcon, XCircleIcon } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { SafeMarkdown } from "@/features/academy/components/safe-markdown"
import { lessonPath } from "@/features/academy/lib/paths"
import { loginUrl } from "@/lib/routes"
import { cn } from "@/lib/utils"

import {
  fetchCourseProgress,
  fetchQuizAttempt,
  fetchQuizAttempts,
  learningErrorMessage,
  startQuizAttempt,
  submitQuizAttempt,
} from "../api/learning"
import type { QuizAttemptsLoad } from "../api/progress"
import {
  formatPercent,
  type AttemptDetail,
  type AttemptResult,
  type AttemptSummary,
  type OpenAttempt,
} from "../lib/schemas"
import { useHydrated } from "../lib/use-hydrated"
import { useCourseLearning } from "./course-learning-provider"

/** Dados públicos do quiz, do bloco `quiz_ref` da aula (sem perguntas nem gabarito). */
export type QuizInfo = {
  quiz: string
  title: string
  question_count: number
  max_attempts: number
  pass_percent: number
}

type View =
  | { mode: "overview" }
  | { mode: "taking"; attempt: OpenAttempt; answers: Record<string, string[]> }
  | { mode: "result"; result: AttemptResult }

/**
 * Quiz da aula (etapa 2.5, ADR 0018), no lugar do bloco `quiz_ref`.
 *
 * Tudo o que importa vem da API: perguntas sem gabarito ao iniciar, correção e nota no servidor,
 * e gabarito e explicações só quando a API libera a revisão (aprovado ou tentativas esgotadas).
 * O navegador só envia as opções escolhidas. O estado inicial (tentativas do jogador) é lido no
 * servidor; cada ação troca o estado pela resposta da API.
 */
export function QuizPanel({
  quiz,
  lesson,
  initial,
}: {
  quiz: QuizInfo
  lesson: string
  initial: QuizAttemptsLoad
}) {
  const learning = useCourseLearning()
  const hydrated = useHydrated()
  const [attempts, setAttempts] = useState<AttemptSummary[]>(
    initial.kind === "player" ? initial.attempts : [],
  )
  const [view, setView] = useState<View>({ mode: "overview" })
  const [error, setError] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState("")
  const [pending, startTransition] = useTransition()

  const course = learning?.course ?? ""
  const passed = attempts.some((attempt) => attempt.status === "submitted" && attempt.passed)
  const open = attempts.find((attempt) => attempt.status === "in_progress") ?? null
  const latest = attempts.find((attempt) => attempt.status === "submitted") ?? null
  const remaining = Math.max(0, quiz.max_attempts - attempts.length)
  const busy = pending || !hydrated

  /** Depois de cada ação: tentativas e progresso do curso relidos da API (melhor esforço). */
  async function refresh() {
    try {
      setAttempts(await fetchQuizAttempts(quiz.quiz))
    } catch {
      // A tela continua com o estado da última resposta; a próxima ação relê.
    }
    if (learning && course) {
      try {
        learning.setProgress(await fetchCourseProgress(course))
      } catch {
        // idem
      }
    }
  }

  function show(detail: AttemptDetail) {
    if (detail.status === "in_progress") {
      setView({ mode: "taking", attempt: detail, answers: {} })
    } else {
      setView({ mode: "result", result: detail })
    }
  }

  function run(action: () => Promise<void>) {
    setError(null)
    startTransition(async () => {
      try {
        await action()
      } catch (failure) {
        setError(learningErrorMessage(failure))
        await refresh()
      }
    })
  }

  const start = () =>
    run(async () => {
      show(await startQuizAttempt(quiz.quiz))
      await refresh()
    })

  const openResult = (id: string) => run(async () => show(await fetchQuizAttempt(id)))

  const submit = (attempt: OpenAttempt, answers: Record<string, string[]>) =>
    run(async () => {
      const detail = await submitQuizAttempt(
        attempt.id,
        attempt.questions.map((question) => ({
          question_id: question.id,
          option_ids: answers[question.id] ?? [],
        })),
      )
      show(detail)
      if (detail.status === "submitted") {
        setAnnouncement(
          `Você acertou ${detail.correct_count} de ${detail.question_count} (${formatPercent(detail.score_percent)}). ${detail.passed ? "Aprovado." : "Não aprovado."}`,
        )
      }
      await refresh()
    })

  if (learning === null) return null

  return (
    <div className="mt-4 flex flex-col gap-4" data-testid="quiz-panel">
      {learning.state.kind === "anonymous" && (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted-foreground">
            Entre para responder ao quiz e acompanhar sua nota.
          </p>
          <Button asChild variant="outline" className="w-fit">
            <Link href={loginUrl(lessonPath(course, lesson))}>
              <LogInIcon aria-hidden />
              Entrar para responder
            </Link>
          </Button>
        </div>
      )}

      {(learning.state.kind === "unavailable" || initial.kind === "unavailable") &&
        learning.state.kind !== "anonymous" && (
          <p className="text-sm text-muted-foreground">
            Não foi possível carregar o quiz agora. Tente de novo em instantes.
          </p>
        )}

      {learning.state.kind === "player" && initial.kind !== "unavailable" && (
        <>
          {view.mode === "overview" && (
            <QuizOverview
              passed={passed}
              open={open}
              latest={latest}
              remaining={remaining}
              busy={busy}
              onStart={start}
              onOpenResult={openResult}
            />
          )}
          {view.mode === "taking" && (
            <QuizForm
              quizKey={quiz.quiz}
              attempt={view.attempt}
              answers={view.answers}
              busy={busy}
              onChange={(answers) => setView({ ...view, answers })}
              onSubmit={() => submit(view.attempt, view.answers)}
            />
          )}
          {view.mode === "result" && (
            <QuizResult
              quizKey={quiz.quiz}
              result={view.result}
              passPercent={quiz.pass_percent}
              canRetry={!passed && remaining > 0}
              busy={busy}
              onRetry={start}
              onBack={() => setView({ mode: "overview" })}
            />
          )}
        </>
      )}

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <p aria-live="polite" className="sr-only">
        {announcement}
      </p>
    </div>
  )
}

function QuizOverview({
  passed,
  open,
  latest,
  remaining,
  busy,
  onStart,
  onOpenResult,
}: {
  passed: boolean
  open: AttemptSummary | null
  latest: AttemptSummary | null
  remaining: number
  busy: boolean
  onStart: () => void
  onOpenResult: (id: string) => void
}) {
  const remainingText = `${remaining} ${remaining === 1 ? "tentativa restante" : "tentativas restantes"}`

  // Depois de aprovado não há tentativa nova (R16a): a última enviada é a aprovada.
  if (passed && latest) {
    const approved = latest
    return (
      <div className="flex flex-col gap-3">
        <p className="inline-flex items-center gap-2 font-medium text-success">
          <CheckCircle2Icon aria-hidden className="size-5" />
          Você foi aprovado neste quiz
          {approved.status === "submitted" && ` com ${formatPercent(approved.score_percent)}`}.
        </p>
        <Button
          variant="outline"
          className="w-fit"
          disabled={busy}
          onClick={() => onOpenResult(approved.id)}
        >
          Ver revisão
        </Button>
      </div>
    )
  }

  if (open) {
    return (
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">Você tem uma tentativa em andamento.</p>
        <Button className="w-fit" disabled={busy} onClick={onStart}>
          Continuar tentativa
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3">
      {latest && latest.status === "submitted" && (
        <p className="text-sm">
          Última tentativa:{" "}
          <span className="font-medium">{formatPercent(latest.score_percent)}</span>
          {" · "}
          {latest.passed ? "aprovado" : "não aprovado"}{" "}
          <Button
            variant="link"
            className="h-auto p-0"
            disabled={busy}
            onClick={() => onOpenResult(latest.id)}
          >
            {remaining > 0 ? "Ver resultado" : "Ver revisão"}
          </Button>
        </p>
      )}
      {remaining > 0 ? (
        <div className="flex flex-wrap items-center gap-3">
          <Button className="w-fit" disabled={busy} onClick={onStart}>
            {latest ? "Tentar de novo" : "Iniciar quiz"}
          </Button>
          <span className="text-sm text-muted-foreground">{remainingText}</span>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">
          Suas tentativas acabaram. A revisão com o gabarito está liberada.
        </p>
      )}
    </div>
  )
}

function QuizForm({
  quizKey,
  attempt,
  answers,
  busy,
  onChange,
  onSubmit,
}: {
  quizKey: string
  attempt: OpenAttempt
  answers: Record<string, string[]>
  busy: boolean
  onChange: (answers: Record<string, string[]>) => void
  onSubmit: () => void
}) {
  const answered = attempt.questions.every((question) => (answers[question.id]?.length ?? 0) > 0)

  function toggle(questionId: string, optionId: string, single: boolean) {
    const current = answers[questionId] ?? []
    const next = single
      ? [optionId]
      : current.includes(optionId)
        ? current.filter((id) => id !== optionId)
        : [...current, optionId]
    onChange({ ...answers, [questionId]: next })
  }

  return (
    <form
      aria-label={`Tentativa ${attempt.attempt_number} de ${attempt.max_attempts}`}
      className="flex flex-col gap-6"
      onSubmit={(event) => {
        event.preventDefault()
        if (answered) onSubmit()
      }}
    >
      <p className="text-sm text-muted-foreground">
        Tentativa {attempt.attempt_number} de {attempt.max_attempts}
      </p>
      {attempt.questions.map((question, index) => {
        const single = question.kind === "single_choice"
        const hintId = `${quizKey}-${question.id}-hint`
        return (
          <fieldset key={question.id} aria-describedby={hintId} className="flex flex-col gap-3">
            <legend className="font-medium">
              <span className="sr-only">Pergunta {index + 1}: </span>
              <SafeMarkdown text={question.prompt} inline as="span" />
            </legend>
            <p id={hintId} className="text-xs text-muted-foreground">
              {single ? "Escolha uma opção." : "Marque todas as corretas."}
            </p>
            <div className="flex flex-col gap-2">
              {question.options.map((option) => {
                const id = `${quizKey}-${question.id}-${option.id}`
                const checked = answers[question.id]?.includes(option.id) ?? false
                return (
                  <label
                    key={option.id}
                    htmlFor={id}
                    className={cn(
                      "flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50",
                      checked ? "border-primary bg-primary/10" : "hover:bg-accent",
                    )}
                  >
                    <input
                      id={id}
                      type={single ? "radio" : "checkbox"}
                      name={`${quizKey}-${question.id}`}
                      value={option.id}
                      checked={checked}
                      onChange={() => toggle(question.id, option.id, single)}
                      className="size-4 accent-primary"
                    />
                    <SafeMarkdown text={option.text} inline as="span" className="text-sm" />
                  </label>
                )
              })}
            </div>
          </fieldset>
        )
      })}
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={busy || !answered}>
          Enviar respostas
        </Button>
        {!answered && (
          <span className="text-sm text-muted-foreground">
            Responda todas as perguntas para enviar.
          </span>
        )}
      </div>
    </form>
  )
}

function QuizResult({
  quizKey,
  result,
  passPercent,
  canRetry,
  busy,
  onRetry,
  onBack,
}: {
  quizKey: string
  result: AttemptResult
  passPercent: number
  canRetry: boolean
  busy: boolean
  onRetry: () => void
  onBack: () => void
}) {
  const titleId = `${quizKey}-result-${result.id}`
  return (
    <section aria-labelledby={titleId} className="flex flex-col gap-5">
      <div className="flex flex-col gap-2">
        <h3 id={titleId} className="text-base font-semibold">
          Resultado da tentativa {result.attempt_number}
        </h3>
        <div className="flex flex-wrap items-center gap-3">
          <span className="font-mono text-3xl font-semibold">
            {formatPercent(result.score_percent)}
          </span>
          <Badge variant={result.passed ? "default" : "secondary"}>
            {result.passed ? "Aprovado" : "Não aprovado"}
          </Badge>
        </div>
        <p className="text-sm text-muted-foreground">
          {result.correct_count} de {result.question_count}{" "}
          {result.question_count === 1 ? "pergunta certa" : "perguntas certas"} · nota mínima{" "}
          {passPercent}% · {result.attempts_remaining}{" "}
          {result.attempts_remaining === 1 ? "tentativa restante" : "tentativas restantes"}
        </p>
        {!result.review_available && (
          <p className="text-sm text-muted-foreground">
            O gabarito e as explicações aparecem quando você for aprovado ou usar todas as
            tentativas.
          </p>
        )}
      </div>

      <ol className="flex flex-col gap-4">
        {result.questions.map((question, index) => (
          <li key={question.id} className="flex flex-col gap-2 rounded-lg border p-4">
            <p className="flex items-start gap-2 font-medium">
              {question.is_correct ? (
                <CheckCircle2Icon aria-hidden className="mt-0.5 size-5 shrink-0 text-success" />
              ) : (
                <XCircleIcon aria-hidden className="mt-0.5 size-5 shrink-0 text-destructive" />
              )}
              <span>
                <span className="sr-only">
                  Pergunta {index + 1}, {question.is_correct ? "certa" : "errada"}:{" "}
                </span>
                <SafeMarkdown text={question.prompt} inline as="span" />
              </span>
            </p>
            <ul className="flex flex-col gap-1 text-sm">
              {question.options.map((option) => {
                const selected = question.selected_option_ids.includes(option.id)
                const correct = "correct" in option ? option.correct : undefined
                if (!result.review_available && !selected) return null
                return (
                  <li key={option.id} className="flex flex-wrap items-center gap-2">
                    <SafeMarkdown text={option.text} inline as="span" className="text-sm" />
                    {selected && <Badge variant="outline">Sua resposta</Badge>}
                    {correct === true && <Badge variant="secondary">Correta</Badge>}
                  </li>
                )
              })}
            </ul>
            {"explanation" in question && question.explanation && (
              <div className="rounded-md bg-muted/60 p-3 text-sm">
                <span className="font-medium">Explicação: </span>
                <SafeMarkdown text={question.explanation} inline as="span" className="text-sm" />
              </div>
            )}
          </li>
        ))}
      </ol>

      <div className="flex flex-wrap gap-3">
        {canRetry && (
          <Button disabled={busy} onClick={onRetry}>
            Tentar de novo
          </Button>
        )}
        <Button variant="outline" disabled={busy} onClick={onBack}>
          Voltar ao quiz
        </Button>
      </div>
    </section>
  )
}
