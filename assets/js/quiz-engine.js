// ============================================
// assets/js/quiz-engine.js — Alpine Component
// ============================================

function QuizEngine() {
  return {
    // State
    quiz: null,
    questions: [],
    currentIndex: 0,
    answers: {},       // { questionId: optionId }
    flagged: new Set(),
    timeLeft: 0,
    timerInterval: null,
    phase: 'loading',  // loading | ready | playing | reviewing | submitted
    attemptId: null,
    result: null,
    showNav: false,
    loading: false,
    error: null,

    // Computed
    get current() { return this.questions[this.currentIndex] || null; },
    get progress() { return this.questions.length ? Math.round((Object.keys(this.answers).length / this.questions.length) * 100) : 0; },
    get answered() { return Object.keys(this.answers).length; },
    get timerClass() {
      if (this.timeLeft > 60)  return 'timer-ok';
      if (this.timeLeft > 20)  return 'timer-warning';
      return 'timer-danger';
    },
    get timerDisplay() { return formatTime(this.timeLeft); },

    // Load quiz
    async loadQuiz(quizId) {
      this.phase = 'loading';
      this.error = null;
      try {
        const data = await api.get('quiz.detail', { id: quizId });
        this.quiz = data.quiz;
        this.questions = data.questions;
        this.timeLeft = this.quiz.time_limit || 600;
        this.answers = {};
        this.flagged = new Set();
        this.currentIndex = 0;
        this.phase = 'ready';
      } catch (e) {
        this.error = e.message;
        this.phase = 'error';
      }
    },

    // Start quiz
    startQuiz() {
      this.phase = 'playing';
      this.startTimer();
    },

    // Timer
    startTimer() {
      clearInterval(this.timerInterval);
      this.timerInterval = setInterval(() => {
        if (this.timeLeft > 0) {
          this.timeLeft--;
        } else {
          clearInterval(this.timerInterval);
          this.autoSubmit();
        }
      }, 1000);
    },

    stopTimer() {
      clearInterval(this.timerInterval);
    },

    // Select answer
    selectOption(questionId, optionId) {
      if (this.phase !== 'playing') return;
      this.answers[questionId] = optionId;
      // Auto-advance after short delay
      if (this.currentIndex < this.questions.length - 1) {
        setTimeout(() => {
          if (this.answers[questionId] === optionId) { // still selected
            this.next();
          }
        }, 600);
      }
    },

    isSelected(questionId, optionId) {
      return this.answers[questionId] === optionId;
    },

    // Navigation
    next() {
      if (this.currentIndex < this.questions.length - 1) this.currentIndex++;
    },
    prev() {
      if (this.currentIndex > 0) this.currentIndex--;
    },
    goTo(index) {
      this.currentIndex = index;
      this.showNav = false;
    },

    toggleFlag(qId) {
      if (this.flagged.has(qId)) this.flagged.delete(qId);
      else this.flagged.add(qId);
      this.flagged = new Set(this.flagged); // trigger reactivity
    },

    questionStatus(index) {
      const q = this.questions[index];
      if (!q) return 'unanswered';
      if (this.flagged.has(q.id)) return 'flagged';
      if (this.answers[q.id]) return 'answered';
      return 'unanswered';
    },

    // Submit
    async autoSubmit() {
      this.stopTimer();
      await this.submitAnswers();
    },

    async submit() {
      const unanswered = this.questions.length - Object.keys(this.answers).length;
      if (unanswered > 0) {
        const ok = confirm(`Masih ada ${unanswered} soal belum dijawab. Yakin ingin submit?`);
        if (!ok) return;
      }
      this.stopTimer();
      await this.submitAnswers();
    },

    async submitAnswers() {
      this.loading = true;
      try {
        const payload = {
          quiz_id: this.quiz.id,
          answers: Object.entries(this.answers).map(([question_id, option_id]) => ({
            question_id: parseInt(question_id),
            option_id: parseInt(option_id),
          })),
          time_taken: (this.quiz.time_limit || 600) - this.timeLeft,
        };
        const result = await api.post('attempt.submit', payload);
        this.result = result;
        this.phase = 'submitted';
        // Navigate to result page
        window.location.hash = `#/result/${result.attempt_id}`;
      } catch (e) {
        alert('Gagal submit: ' + e.message);
        this.loading = false;
      }
    },

    // Review mode (post-submit, from result page)
    async loadReview(attemptId) {
      this.phase = 'loading';
      try {
        const data = await api.get('attempt.result', { id: attemptId });
        this.result = data;
        this.quiz = data.quiz;
        this.questions = data.questions || [];
        // Reconstruct answers from result
        this.answers = {};
        if (data.answers) {
          data.answers.forEach(a => {
            this.answers[a.question_id] = a.selected_option_id;
          });
        }
        this.currentIndex = 0;
        this.phase = 'reviewing';
      } catch (e) {
        this.error = e.message;
        this.phase = 'error';
      }
    },

    optionClass(question, optionId) {
      if (this.phase === 'playing') {
        return this.answers[question.id] === optionId ? 'selected' : '';
      }
      if (this.phase === 'reviewing') {
        const isCorrect = optionId === question.correct_option_id;
        const isSelected = this.answers[question.id] === optionId;
        if (isCorrect) return 'correct';
        if (isSelected && !isCorrect) return 'incorrect';
      }
      return '';
    },

    destroy() {
      this.stopTimer();
    },
  };
}
