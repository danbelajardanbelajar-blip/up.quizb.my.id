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
    heartbeatInterval: null,
    playerName: '',        // nama tamu (dari localStorage, opsional)
    questionTimeLeft: 0,    // timer per soal (instant/end mode)
    questionTimerInterval: null,
    questionTimerDefault: 20, // detik per soal

    // ── Audio ──────────────────────────────────
    _ac: null,          // AudioContext
    _bgGain: null,      // master gain node untuk bg music
    _bgNodes: [],       // semua oscillator bg (untuk di-stop)
    _hbTimeout: null,   // heartbeat timeout id

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
    get questionTimerClass() {
      if (this.questionTimeLeft > 10) return 'timer-ok';
      if (this.questionTimeLeft > 5)  return 'timer-warning';
      return 'timer-danger';
    },

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

    // ---- Alpine init: watch currentIndex to reset question timer ----
    init() {
      this.$watch('currentIndex', () => {
        if (this.isReviewMode && this.phase === 'playing') {
          this.startQuestionTimer();
        }
      });
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
        // Gunakan exam_duration (dari setting user/assignment) atau fallback ke time_limit quiz
        this.timeLeft  = this.quiz.exam_duration || this.quiz.time_limit || this.quiz.duration || 600;
        // Gunakan timer_per_question dari setting (user/assignment), default 20
        this.questionTimerDefault = this.quiz.timer_per_question || 20;
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
      this.startBgMusic();           // 🔊 musik latar mulai
      if (this.isReviewMode) {
        this.startQuestionTimer();
      } else {
        this.startTimer();
      }
      if (this.assignmentId) this._startHeartbeat();
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

    // ---- Per-question timer (instant / end review) ----
    startQuestionTimer() {
      clearInterval(this.questionTimerInterval);
      this.questionTimeLeft = this.questionTimerDefault;
      this.questionTimerInterval = setInterval(() => {
        if (this.questionTimeLeft > 0) {
          this.questionTimeLeft--;
        } else {
          clearInterval(this.questionTimerInterval);
          this.onQuestionTimeout();
        }
      }, 1000);
    },

    stopQuestionTimer() {
      clearInterval(this.questionTimerInterval);
    },

    _startHeartbeat() {
      clearInterval(this.heartbeatInterval);
      this._sendHeartbeat();
      this.heartbeatInterval = setInterval(() => this._sendHeartbeat(), 8000);
    },
    _stopHeartbeat() {
      clearInterval(this.heartbeatInterval);
      this.heartbeatInterval = null;
    },
    async _sendHeartbeat() {
      if (!this.assignmentId || this.phase !== 'playing') return;
      try {
        const res = await api.post('assignment.progress_update', {
          assignment_id:    parseInt(this.assignmentId),
          current_question: this.currentIndex + 1,
          total_questions:  this.questions.length,
        });
        if (res && res.is_forced_stop) {
          this._stopHeartbeat();
          this.stopTimer();
          this.stopQuestionTimer();
          alert('⛔ Guru menghentikan pengerjaan Anda. Jawaban dikumpulkan otomatis.');
          await this.submitAnswers();
        }
      } catch (_) {}
    },

    onQuestionTimeout() {
      if (!this.isReviewMode || this.phase !== 'playing') return;
      if (this.mode === 'instant') {
        // Waktu habis = tidak menjawab = game over
        this.submitAnswers();
      } else {
        // End review: lanjut soal berikutnya, atau submit jika soal terakhir
        if (this.currentIndex < this.questions.length - 1) {
          this.next();
        } else {
          this.submitAnswers();
        }
      }
    },

    // ---- Select answer ----
    selectOption(questionId, optionId) {
      if (this.phase !== 'playing') return;
      this.answers[questionId] = optionId;

      // Hentikan timer soal saat jawaban dipilih
      if (this.isReviewMode) this.stopQuestionTimer();

      // ---- INSTANT REVIEW ----
      if (this.mode === 'instant') {
        const q = this.questions.find(q => q.id === questionId);
        const isWrong = q && q.correct_option_id && q.correct_option_id !== optionId;
        if (isWrong) {
          // Jawaban salah → suara gagal, hentikan musik, submit
          this.playWrong();
          this.stopBgMusic();
          this.stopTimer();
          setTimeout(() => this.submitAnswers(), 600);
          return;
        }
        // Jawaban benar → suara sukses, lanjut ke soal berikutnya
        this.playCorrect();
        if (this.currentIndex < this.questions.length - 1) {
          setTimeout(() => { if (this.answers[questionId] === optionId) this.next(); }, 450);
        } else {
          // Soal terakhir dan benar → submit
          setTimeout(() => this.submitAnswers(), 500);
        }
        return;
      }

      // ---- END REVIEW ----
      if (this.mode === 'end') {
        const q2       = this.questions.find(q => q.id === questionId);
        const isRight2 = q2 && q2.correct_option_id && q2.correct_option_id === optionId;
        if (isRight2) this.playCorrect(); else this.playWrong();

        if (this.currentIndex < this.questions.length - 1) {
          setTimeout(() => { if (this.answers[questionId] === optionId) this.next(); }, 450);
        } else {
          // Soal terakhir → auto-submit
          setTimeout(() => this.submitAnswers(), 500);
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
      this._stopHeartbeat();
      this.stopBgMusic();            // 🔇 hentikan musik saat submit
      this.loading = true;
      try {
        const timeTaken = (this.quiz.exam_duration || this.quiz.time_limit || this.quiz.duration || 600) - this.timeLeft;
        const payload = {
          quiz_id:      this.quiz.id,
          mode:         this.mode || 'exam',
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

    // ============================================================
    // AUDIO SYSTEM — Web Audio API (no external files)
    // ============================================================

    /** Inisialisasi AudioContext (harus setelah gesture user) */
    _initAC() {
      if (this._ac) return;
      try {
        this._ac = new (window.AudioContext || window.webkitAudioContext)();
      } catch (_) {}
    },

    /** Mulai musik latar menegangkan */
    startBgMusic() {
      this._initAC();
      const ctx = this._ac;
      if (!ctx) return;
      this.stopBgMusic();

      // Master gain — fade in perlahan
      const master = ctx.createGain();
      master.gain.setValueAtTime(0, ctx.currentTime);
      master.gain.linearRampToValueAtTime(0.18, ctx.currentTime + 2.5);
      master.connect(ctx.destination);
      this._bgGain = master;

      // Reverb sederhana (convolver dihilangkan — cukup delay feedback)
      const delay = ctx.createDelay(0.5);
      delay.delayTime.value = 0.35;
      const fbGain = ctx.createGain();
      fbGain.gain.value = 0.35;
      delay.connect(fbGain);
      fbGain.connect(delay);
      delay.connect(master);

      // Helper: buat satu drone oscillator
      const makeDrone = (freq, vol, type = 'sawtooth', detune = 0) => {
        const osc = ctx.createOscillator();
        const g   = ctx.createGain();
        const lpf = ctx.createBiquadFilter();
        osc.type            = type;
        osc.frequency.value = freq;
        osc.detune.value    = detune;
        lpf.type            = 'lowpass';
        lpf.frequency.value = freq * 4;
        lpf.Q.value         = 2;
        g.gain.value        = vol;
        osc.connect(lpf); lpf.connect(g); g.connect(master);
        osc.start();
        this._bgNodes.push(osc);
      };

      // Nada: E minor — E2, B2, D3 (minor 7th → tense)
      makeDrone(82.4,  0.45, 'sawtooth', 0);    // E2 root
      makeDrone(82.4,  0.20, 'sawtooth', 10);   // E2 sedikit detune
      makeDrone(123.5, 0.18, 'sawtooth', 0);    // B2 fifth
      makeDrone(146.8, 0.10, 'sine',     0);    // D3 minor 7th
      makeDrone(164.8, 0.07, 'sine',     -5);   // E3 octave atas

      // LFO tremolo — bikin suara berdenyut
      const lfo  = ctx.createOscillator();
      const lfoG = ctx.createGain();
      lfo.type            = 'sine';
      lfo.frequency.value = 1.4;
      lfoG.gain.value     = 0.07;
      lfo.connect(lfoG);
      lfoG.connect(master.gain);
      lfo.start();
      this._bgNodes.push(lfo);

      // Detuned high shimmer
      makeDrone(329.6, 0.04, 'sine', 12);   // E4 shimmer

      // Jadwalkan heartbeat
      this._scheduleHb();
    },

    /** Heartbeat thud — "dug-dug" berulang */
    _scheduleHb() {
      if (!this._bgGain) return;
      const ctx = this._ac;

      const thud = (t, startFreq, endFreq, vol) => {
        const osc = ctx.createOscillator();
        const g   = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(startFreq, t);
        osc.frequency.exponentialRampToValueAtTime(endFreq, t + 0.18);
        g.gain.setValueAtTime(vol, t);
        g.gain.exponentialRampToValueAtTime(0.001, t + 0.28);
        osc.connect(g); g.connect(this._bgGain);
        osc.start(t); osc.stop(t + 0.32);
      };

      const beat = () => {
        if (!this._bgGain) return;
        const t = ctx.currentTime;
        thud(t,        130, 45, 0.55);   // ketukan 1
        thud(t + 0.22, 110, 38, 0.35);   // ketukan 2 (echo)
        this._hbTimeout = setTimeout(beat, 1400);
      };

      this._hbTimeout = setTimeout(beat, 800);
    },

    /** Hentikan musik latar dengan fade out */
    stopBgMusic() {
      clearTimeout(this._hbTimeout);
      this._hbTimeout = null;
      if (!this._bgGain || !this._ac) return;
      const ctx = this._ac;
      const g   = this._bgGain;
      try {
        g.gain.cancelScheduledValues(ctx.currentTime);
        g.gain.setValueAtTime(g.gain.value, ctx.currentTime);
        g.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.6);
      } catch (_) {}
      const nodes = this._bgNodes.slice();
      this._bgNodes = [];
      this._bgGain  = null;
      setTimeout(() => nodes.forEach(n => { try { n.stop(); } catch (_) {} }), 700);
    },

    /** Suara benar: arpeggio naik C–E–G */
    playCorrect() {
      this._initAC();
      const ctx = this._ac;
      if (!ctx) return;
      [523.25, 659.25, 783.99].forEach((freq, i) => {
        const t    = ctx.currentTime + i * 0.09;
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type            = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0, t);
        gain.gain.linearRampToValueAtTime(0.35, t + 0.04);
        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.35);
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start(t); osc.stop(t + 0.4);
      });
    },

    /** Suara salah: buzzer turun + noise singkat */
    playWrong() {
      this._initAC();
      const ctx = this._ac;
      if (!ctx) return;

      // Buzzer turun
      const osc  = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(320, ctx.currentTime);
      osc.frequency.linearRampToValueAtTime(80, ctx.currentTime + 0.45);
      gain.gain.setValueAtTime(0.35, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(); osc.stop(ctx.currentTime + 0.55);

      // Nada rendah "bum" tambahan
      const osc2  = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type             = 'sine';
      osc2.frequency.value  = 110;
      gain2.gain.setValueAtTime(0.25, ctx.currentTime);
      gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
      osc2.connect(gain2); gain2.connect(ctx.destination);
      osc2.start(); osc2.stop(ctx.currentTime + 0.65);
    },

    destroy() {
      this.stopTimer();
      this.stopQuestionTimer();
      this._stopHeartbeat();
      this.stopBgMusic();
    },
  };
}
