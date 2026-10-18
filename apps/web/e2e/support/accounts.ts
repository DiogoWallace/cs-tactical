/**
 * Contas criadas pelo `php artisan e2e:prepare` (PrepareE2eDatabaseCommand.php). Uma por fluxo
 * que altera dados, para os specs rodarem em paralelo sem interferir entre si.
 */
export const E2E_PASSWORD = "e2e-Senha-Segura-123"

export const ACCOUNTS = {
  login: { email: "login@e2e.test", nickname: "e2e_login" },
  remember: { email: "remember@e2e.test", nickname: "e2e_remember" },
  session: { email: "session@e2e.test", nickname: "e2e_session" },
  guard: { email: "guard@e2e.test", nickname: "e2e_guard" },
  redirect: { email: "redirect@e2e.test", nickname: "e2e_redirect" },
  verify: { email: "verify@e2e.test", nickname: "e2e_verify" },
  reset: { email: "reset@e2e.test", nickname: "e2e_reset" },
  profile: { email: "profile@e2e.test", nickname: "e2e_profile" },
  email: { email: "email@e2e.test", nickname: "e2e_email" },
  password: { email: "password@e2e.test", nickname: "e2e_password" },
  mobile: { email: "mobile@e2e.test", nickname: "e2e_mobile" },
  a11y: { email: "a11y@e2e.test", nickname: "e2e_a11y" },
  csp: { email: "csp@e2e.test", nickname: "e2e_csp" },
  learning: { email: "learning@e2e.test", nickname: "e2e_learning" },
  learningA11y: { email: "learning-a11y@e2e.test", nickname: "e2e_learning_a11y" },
  quiz: { email: "quiz@e2e.test", nickname: "e2e_quiz" },
  quizA11y: { email: "quiz-a11y@e2e.test", nickname: "e2e_quiz_a11y" },
  navigation: { email: "navigation@e2e.test", nickname: "e2e_navigation" },
  navigationMobile: { email: "navigation-mobile@e2e.test", nickname: "e2e_nav_mobile" },
  navigationA11y: { email: "navigation-a11y@e2e.test", nickname: "e2e_navigation_a11y" },
  dashboard: { email: "dashboard@e2e.test", nickname: "e2e_dashboard" },
} as const

export type Account = { email: string; nickname: string }
