// ============================================
// assets/js/quiz-engine.js — Alpine Component
// v3.0: Mode support (exam | instant | end | challenge)
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
    assignmentId: null,   // jika dimainkan dari tugas
    mode: 'exam',         // exam | instant | end | challenge
    challengeId: null,    // untuk mode challenge
    playerName: '',        // nama tamu (dari localStorage, opsional)

    // Computed
    get current() { return this.questions[this.currentIndex] || null; },
    get progress() {
      return this.questions.length
        ? Math.round((Object.keys(this.answers).length / this.questions.length) * 100)
        : 0;
    },
    get answered() { return Object.keys(this.answers).length; },
    get timerClass() {
      if (this.timeLeft > 60)  return 'timer-ok';
      if (this.timeLeft > 20)  return 'timer-warning';
      return 'timer-danger';
    },
    get timerDisplay() { return formatTime(this.timeLeft); },

    // Mode helpers
    get isReviewMode() { return this.mode === 'instant' || this.mode === 'end'; },
    get modeLabel() {
      return { exam: '🎯 Mode Ujian', instant: '⚡ Instant Review', end: '📖 End Review', challenge: '⚔️ Tantangan' }[this.mode] || '';
    },
    get modeBadgeClass() {
      return {
        exam:      'bg-blue-100   text-blue-700   dark:bg-blue-900/40   dark:text-blue-300',
        instant:   'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
        end:       'bg-green-100  text-green-700  dark:bg-green-900/40  dark:text-green-300',
        challenge: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
      }[this.mode] || '';
    },

    // ---- Option label helpers (A, B, C, D, E) ----
    optionLabel(index) {
      return ['A', 'B', 'C', 'D', 'E'][index] || String.fromCharCode(65 + index);
    },

    get currentOptionCount() {
      return this.current?.options?.length || 0;
    },

    // ---- Load quiz ----
    async loadQuiz(quizId, assignmentId = null, mode = 'exam', challengeId = null) {
      this.phase = 'loading';
      this.error = null;
      this.assignmentId = assignmentId;
      this.mode         = mode || 'exam';
      this.challengeId  = challengeId ? parseInt(challengeId) : null;
      try {
        const params = { id: quizId };
        if (assignmentId) params.assignment_id = assignmentId;
        const data = await api.get('quiz.questions', params);
        this.quiz      = data.quiz;
        this.questions = data.questions;
        this.timeLeft  = this.quiz.time_limit || this.quiz.duration || 600;
        this.answers   = {};
        this.flagged   = new Set();
        this.currentIndex = 0;
        // Baca nama tamu dari localStorage (jika user tidak login)
      this.playerName = (typeof localStorage !== 'undefined' ? localStorage.getItem('quizb_guest_name') : '') || '';
      this.phase = 'ready';
      } catch (e) {
        this.error = e.message;
        this.phase = 'error';
      }
    },

    // ---- Start quiz ----
    startQuiz() {
      this.phase = 'playing';
      this.startTimer();
    },

    // ---- Timer ----
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

    // ---- Select answer ----
    selectOption(questionId, optionId) {
      if (this.phase !== 'playing') return;
      this.answers[questionId] = optionId;

      // ---- INSTANT REVIEW ----
      if (this.mode === 'instant') {
        const q = this.questions.find(q => q.id === questionId);
        const isWrong = q && q.correct_option_id && q.correct_option_id !== optionId;
        if (isWrong) {
          // Jawaban salah → langsung submit (game over)
          this.stopTimer();
          setTimeout(() => this.submitAnswers(), 350);
          return;
        }
        // Jawaban benar → lanjut ke soal berikutnya
        if (this.currentIndex < this.questions.length - 1) {
          setTimeout(() => { if (this.answers[questionId] === optionId) this.next(); }, 450);
        } else {
          // Soal terakhir dan benar → submit
          setTimeout(() => this.submitAnswers(), 450);
        }
        return;
      }

      // ---- END REVIEW ----
      if (this.mode === 'end') {
        if (this.currentIndex < this.questions.length - 1) {
          setTimeout(() => { if (this.answers[questionId] === optionId) this.next(); }, 450);
        } else {
          // Soal terakhir → auto-submit
          setTimeout(() => this.submitAnswers(), 450);
        }
        return;
      }

      // ---- EXAM / CHALLENGE ---- auto-advance jika bukan soal terakhir
      if (this.currentIndex < this.questions.length - 1) {
        setTimeout(() => {
          if (this.answers[questionId] === optionId) this.next();
        }, 500);
      }
    },

    isSelected(questionId, optionId) {
      return this.answers[questionId] === optionId;
    },

    // ---- Navigation (hanya exam/challenge) ----
    next() {
      if (this.currentIndex < this.questions.length - 1) this.currentIndex++;
    },
    prev() {
      if (this.isReviewMode) return; // tidak boleh kembali di review mode
      if (this.currentIndex > 0) this.currentIndex--;
    },
    goTo(index) {
      if (this.isReviewMode) return;
      this.currentIndex = index;
      this.showNav = false;
    },

    toggleFlag(qId) {
      if (this.isReviewMode) return;
      if (this.flagged.has(qId)) this.flagged.delete(qId);
      else this.flagged.add(qId);
      this.flagged = new Set(this.flagged);
    },

    questionStatus(index) {
      const q = this.questions[index];
      if (!q) return 'unanswered';
      if (this.flagged.has(q.id)) return 'flagged';
      if (this.answers[q.id])     return 'answered';
      return 'unanswered';
    },

    questionStatusClass(index) {
      const status = this.questionStatus(index);
      return {
        'unanswered': 'bg-gray-200 dark:bg-gray-700 text-gray-600',
        'answered':   'bg-indigo-500 text-white',
        'flagged':    'bg-yellow-400 text-white',
      }[status] || '';
    },

    // ---- Submit ----
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
        const timeTaken = (this.quiz.time_limit || this.quiz.duration || 600) - this.timeLeft;
        const payload = {
          quiz_id:      this.quiz.id,
          player_name:  this.playerName || undefined,
          question_ids: this.questions.map(q => q.id),
          answers: Object.entries(this.answers).map(([question_id, option_id]) => ({
            question_id: parseInt(question_id),
            option_id:   parseInt(option_id),
          })),
          time_taken: timeTaken,
        };
        const result = await api.post('attempt.submit', payload);
        this.result  = result;
        this.phase   = 'submitted';

        // Jika dari tugas: auto-submit ke assignment
        if (this.assignmentId && result.attempt_id) {
          try {
            await api.post('assignment.submit', {
              assignment_id: parseInt(this.assignmentId),
              attempt_id:    result.attempt_id,
            });
          } catch (_) {}
        }

        // Jika dari challenge: submit hasil ke challenge
        if (this.challengeId && result.attempt_id) {
          try {
            await api.post('challenge.submit', {
              challenge_id: this.challengeId,
              attempt_id:   result.attempt_id,
            });
          } catch (_) {}
        }

        // Navigasi ke result, sertakan challenge ID jika ada
        // Build result URL — sertakan mode & challenge ID sebagai query params
        let resultHash = `#/result/${result.attempt_id}`;
        const qp = [];
        if (this.mode && this.mode !== 'exam') qp.push(`mode=${this.mode}`);
        if (this.challengeId) qp.push(`cid=${this.challengeId}`);
        if (qp.length) resultHash += '?' + qp.join('&');
        window.location.hash = resultHash;

      } catch (e) {
        if (e.message && e.message.includes('401')) {
          alert('Sesi berakhir. Silakan refresh halaman dan coba lagi.');
        } else {
          alert('Gagal submit: ' + e.message);
        }
        this.loading = false;
      }
    },

    // ---- Review mode (post-submit, from result page) ----
    async loadReview(attemptId) {
      this.phase = 'loading';
      try {
        const data = await api.get('attempt.result', { id: attemptId });
        this.result    = data;
        this.quiz      = data.quiz;
        this.questions = data.questions || [];
        this.answers   = {};
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

    // ---- Option styling ----
    optionClass(question, optionId) {
      if (this.phase === 'playing') {
        return this.answers[question.id] === optionId
          ? 'option-selected'
          : 'option-default';
      }
      if (this.phase === 'reviewing') {
        const isCorrect  = optionId === question.correct_option_id;
        const isSelected = this.answers[question.id] === optionId;
        if (isCorrect)              return 'option-correct';
        if (isSelected && !isCorrect) return 'option-incorrect';
        return 'option-review-default';
      }
      return 'option-default';
    },

    // ---- Score display helper ----
    scoreGrade(score) {
      if (score >= 90) return { label: 'Sempurna!', emoji: '🏆', cls: 'text-yellow-500' };
      if (score >= 75) return { label: 'Bagus!',    emoji: '⭐', cls: 'text-green-500'  };
      if (score >= 60) return { label: 'Lulus',     emoji: '✅', cls: 'text-blue-500'   };
      return { label: 'Perlu Belajar Lagi', emoji: '📚', cls: 'text-red-500' };
    },

    destroy() {
      this.stopTimer();
    },
  };
}
