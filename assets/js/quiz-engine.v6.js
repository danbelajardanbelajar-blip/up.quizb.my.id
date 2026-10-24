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
    assignment: null,     // data assignment jika dari tugas
    assignmentId: null,   // jika dimainkan dari tugas
    mode: 'exam',         // exam | instant | end | challenge
    challengeId: null,    // untuk mode challenge
    heartbeatInterval: null,
    playerName: '',        // nama tamu (dari localStorage, opsional)
    questionTimeLeft: 0,    // timer per soal (instant/end mode)
    participants: [],       // { name, score, disconnected, ... }
    myScore: 0,
    syncInterval: null,
    syncCountdown: 0,
    questionTimerInterval: null,
    questionTimerDefault: 20, // detik per soal
    
    // Proctoring
    cameraStream: null,
    snapshotInterval: null,

    // ── Audio ──────────────────────────────────
    _ac: null,           // AudioContext
    _bgGain: null,       // master gain node untuk bg music
    _bgNodes: [],        // unused (kept for compat)
    _hbTimeout: null,    // scheduler timeout id
    _nextBeatTime: 0,    // Web Audio clock pointer
    _beatCount: 0,       // 0=ting, 1=tung, 2=ting, …

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
    get isReviewMode() { return this.mode === 'instant' || this.mode === 'end' || this.mode === 'challenge'; },
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
        const params = { id: quizId, mode: this.mode };
        if (assignmentId) params.assignment_id = assignmentId;
        if (this.mode === 'challenge' && this.challengeId) params.challenge_id = this.challengeId;
        const data = await api.get('quiz.questions', params);
        this.quiz      = data.quiz;
        this.questions = data.questions;
        // Initialize participants array (will be populated by challenge.sync)
        if (this.mode === 'challenge' && this.challengeId) {
          this.participants = [];
        }

        // Untuk mode EXAM: 20 detik per soal × jumlah soal
        // Untuk mode lain (challenge, dll): gunakan exam_duration/time_limit sebagai fallback
        if (this.mode === 'exam') {
          const SECS_PER_QUESTION = 20;
          this.timeLeft = SECS_PER_QUESTION * (this.questions.length || 10);
        } else {
          this.timeLeft = this.quiz.exam_duration || this.quiz.time_limit || this.quiz.duration || 600;
        }
        // Gunakan timer_per_question dari setting (user/assignment), default 20
        // Normalize to integer and ensure sensible minimum
        let tpq = null;
        if (this.quiz && this.quiz.timer_per_question != null) {
          tpq = parseInt(this.quiz.timer_per_question, 10);
          if (!Number.isFinite(tpq) || tpq <= 0) tpq = null;
        }
        this.questionTimerDefault = tpq || 20;

        // If assignmentId provided, fetch authoritative assignment record (fallback)
        // to avoid mismatches between quiz response and assignment data.
        if (assignmentId) {
          try {
            const a = await api.get('assignment.get', { id: assignmentId });
            this.assignment = a;
            if (a && a.timer_per_question != null) {
              const atpq = parseInt(a.timer_per_question, 10);
              if (Number.isFinite(atpq) && atpq > 0) this.questionTimerDefault = atpq;
            }
            if (a && a.duration_minutes != null) {
              const dur = parseInt(a.duration_minutes, 10);
              if (Number.isFinite(dur) && dur > 0) this.timeLeft = dur * 60;
            }
            if (a && a.require_camera == 1) {
              this.quiz.require_camera = 1;
            }
          } catch (_) {
            // ignore — already have sane defaults
          }
        }
        this.answers   = {};
        this.flagged   = new Set();
        this.currentIndex = 0;
        // Baca nama tamu dari localStorage (jika user tidak login)
      this.playerName = (typeof localStorage !== 'undefined' ? localStorage.getItem('quizb_guest_name') : '') || '';
      
      if (this.mode === 'challenge' && this.challengeId) {
        this.waitForChallengeStart();
      } else {
        this.startQuiz();
      }
      } catch (e) {
        this.error = e.message;
        this.phase = 'error';
      }
    },

    // ---- Wait for challenge sync ----
    waitForChallengeStart() {
      this.phase = 'syncing';
      this.syncCountdown = 3;
      
      const poll = setInterval(async () => {
        try {
          const res = await api.get('challenge.status', { id: this.challengeId });
          
          if (res.status === 'completed' || res.status === 'expired' || res.status === 'declined') {
            clearInterval(poll);
            this.error = 'Tantangan ini sudah berakhir atau tidak valid.';
            this.phase = 'error';
            return;
          }

          if (res.status === 'playing' && res.start_time) {
            // Convert MySQL dates to JS Dates (assuming they are in same TZ)
            const startT = new Date(res.start_time.replace(' ', 'T')).getTime();
            const serverT = new Date(res.server_now.replace(' ', 'T')).getTime();
            const diff = startT - serverT;
            
            if (diff <= 0) {
              clearInterval(poll);
              this.startQuiz();
              this.startChallengeSync(); // Start real-time sync
            } else {
              this.syncCountdown = Math.ceil(diff / 1000);
            }
          }
        } catch(e) {
          clearInterval(poll);
          this.error = e.message || 'Tantangan tidak ditemukan atau sudah dihapus.';
          this.phase = 'error';
        }
      }, 1000);
    },

    // ---- Start quiz ----
    async startQuiz() {
      if (this.quiz?.require_camera == 1) {
        try {
          this.cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
          // Start snapshot loop after attemptId is set (which happens during loadQuiz? No, attemptId is already created in loadQuiz)
          this.startSnapshotLoop();
        } catch (e) {
          console.warn('Akses kamera ditolak atau tidak tersedia:', e);
          this.phase = 'camera_denied';
          this.error = 'Kuis ini mewajibkan akses kamera (Proctoring). Anda harus mengaktifkan kamera untuk dapat mengerjakan kuis ini.';

          try {
            sessionStorage.setItem('quizb_pending_toast', JSON.stringify({
              msg: 'Wajib mengaktifkan izin kamera untuk mengerjakan kuis ini!',
              type: 'warning',
              icon: '📷'
            }));
          } catch (_) {}

          alert('⚠️ Akses Kamera Diperlukan!\n\nAgar bisa mengerjakan kuis ini, Anda wajib mengaktifkan kamera di browser.\n\nAnda akan dikembalikan ke halaman sebelumnya. Silakan izinkan akses kamera di setelan browser Anda lalu coba kembali.');

          this.exitToPreviousPage();
          return;
        }
      }
      this.phase = 'playing';
      this.startTimeMs = Date.now();
      this.startBgMusic();           // 🔊 musik latar mulai
      if (this.isReviewMode) {
        this.startQuestionTimer();
      } else {
        this.startTimer();
      }
      if (this.assignmentId) this._startHeartbeat();
    },

    // ---- Navigasi Kembali ke Halaman Sebelumnya ----
    exitToPreviousPage() {
      let targetFallback = '';
      if (this.assignment && this.assignment.class_id) {
        targetFallback = '#/classroom/' + this.assignment.class_id;
      } else if (this.assignmentId) {
        targetFallback = '#/classroom';
      } else if (this.quiz && this.quiz.id) {
        targetFallback = '#/quiz/' + this.quiz.id;
      } else {
        targetFallback = '#/dashboard';
      }

      if (window.history.length > 1) {
        window.history.back();
        setTimeout(() => {
          if (window.location.hash.includes('/play/')) {
            window.location.hash = targetFallback;
          }
        }, 350);
      } else {
        window.location.hash = targetFallback;
      }
    },

    // ---- Snapshot Loop ----
    startSnapshotLoop() {
      if (!this.cameraStream) return;
      this.snapshotInterval = setInterval(() => {
        if (this.phase !== 'playing') return;
        this.takeAndUploadSnapshot();
      }, 300000); // 5 minutes
      // Take first snapshot immediately
      setTimeout(() => this.takeAndUploadSnapshot(), 2000);
    },
    
    // ---- Challenge Sync ----
    startChallengeSync() {
      if (this.syncInterval) clearInterval(this.syncInterval);
      this.syncInterval = setInterval(async () => {
        if (this.phase !== 'playing') return;
        try {
          const res = await api.post('challenge.sync', {
            challenge_id: this.challengeId,
            score: this.myScore,
            q_index: this.currentIndex
          });
          if (res.participants) {
            this.participants = res.participants.map(p => ({
              id: p.user_id,
              name: p.name,
              score: p.user_id == (this.user?.id || 0) ? this.myScore : (p.progress_data ? p.progress_data.s : 0),
              q_index: p.progress_data ? p.progress_data.q : 0,
              disconnected: p.disconnected
            }));
            
            // Periksa jika ada partisipan lain yang tersisa (tidak termasuk diri sendiri)
            // Jika semua lawan terputus
            const activeOpponents = this.participants.filter(p => p.id != (this.user?.id || 0) && !p.disconnected);
            if (activeOpponents.length === 0 && this.participants.length > 1) {
                // Jangan otomatis alert menang jika ini multiplayer banyak orang, tapi game status akan jadi 'completed'
                // Biarkan saja, jika completed nanti UI akan menyesuaikan atau bisa auto submit
                if (res.status === 'completed') {
                   clearInterval(this.syncInterval);
                   alert('Semua lawan terputus! Mengakhiri kuis.');
                   this.autoSubmit();
                }
            }
          }
        } catch(e) {}
      }, 2000);
    },
    
    async takeAndUploadSnapshot() {
      if (!this.cameraStream) return;
      if (!this.snapshots) this.snapshots = [];

      try {
        const track = this.cameraStream.getVideoTracks()[0];
        if (!track || track.readyState !== 'live') return;

        // Metoda 1: ImageCapture modern API (didukung Chrome/Android Chrome)
        if (typeof ImageCapture !== 'undefined') {
          try {
            const capturer = new ImageCapture(track);
            const blob = await capturer.takePhoto();
            const reader = new FileReader();
            reader.onloadend = () => {
              if (reader.result) {
                this.snapshots.push(reader.result);
              }
            };
            reader.readAsDataURL(blob);
            return;
          } catch (e) {
            // Fallback ke Canvas jika ImageCapture gagal
          }
        }

        // Metoda 2: Gunakan video element yang stabil & frame sudah didecode
        let video = this._proctorVideo;
        if (!video) {
          video = document.createElement('video');
          video.muted = true;
          video.playsInline = true;
          video.autoplay = true;
          video.srcObject = this.cameraStream;
          await video.play().catch(() => {});
          this._proctorVideo = video;
        }

        // Pastikan video sudah memiliki frame aktual (bukan 0 width atau blank)
        if (video.videoWidth === 0 || video.readyState < 2) {
          await new Promise(r => {
            const onReady = () => {
              video.removeEventListener('loadeddata', onReady);
              r();
            };
            video.addEventListener('loadeddata', onReady);
            setTimeout(r, 600);
          });
        }

        const width = video.videoWidth || 640;
        const height = video.videoHeight || 480;
        if (width > 0 && height > 0) {
          const canvas = document.createElement('canvas');
          canvas.width = Math.min(width, 640);
          canvas.height = Math.round((canvas.width / width) * height);
          const ctx = canvas.getContext('2d');
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
          const imgBase64 = canvas.toDataURL('image/jpeg', 0.6);
          this.snapshots.push(imgBase64);
        }
      } catch (err) {
        console.error('Proctoring snapshot error:', err);
      }
    },
    
    stopCamera() {
      if (this.snapshotInterval) clearInterval(this.snapshotInterval);
      if (this._proctorVideo) {
        try {
          this._proctorVideo.pause();
          this._proctorVideo.srcObject = null;
        } catch (e) {}
        this._proctorVideo = null;
      }
      if (this.cameraStream) {
        this.cameraStream.getTracks().forEach(t => t.stop());
        this.cameraStream = null;
      }
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
        // Waktu habis = tidak menjawab = game over (dimasukkan ke result sebagai waktu habis/salah)
        const currentQ = this.questions[this.currentIndex];
        if (currentQ && !(currentQ.id in this.answers)) {
          this.answers[currentQ.id] = null;
        }
        this.playWrong();
        this.stopBgMusic();
        this.stopTimer();
        this.stopQuestionTimer();
        setTimeout(() => this.submitAnswers(), 600);
      } else {
        // End review: lanjut soal berikutnya, atau submit jika soal terakhir
        const currentQ = this.questions[this.currentIndex];
        if (currentQ && !(currentQ.id in this.answers)) {
          this.answers[currentQ.id] = null;
        }
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
      
      // Hitung skor sementara (khusus challenge)
      let correct = 0;
      for (const qId in this.answers) {
        const q = this.questions.find(x => x.id == qId);
        if (q && q.correct_option_id == this.answers[qId]) correct++;
      }
      this.myScore = Math.round((correct / (this.questions.length || 1)) * 100);
      
      // Update local participants array for instant UI feedback
      if (this.mode === 'challenge' && this.participants) {
          const me = this.participants.find(p => p.id == (this.user?.id || 0));
          if (me) me.score = this.myScore;
      }

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
      } else if (this.mode === 'challenge') {
        // Mode tantangan: soal terakhir langsung auto-submit karena adu cepat
        setTimeout(() => this.submitAnswers(), 500);
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
      this.stopCamera();
      this.loading = true;
      try {
        let timeTaken = 0;
        if (this.startTimeMs) {
          timeTaken = Math.floor((Date.now() - this.startTimeMs) / 1000);
        } else {
          const initialTime = this.mode === 'exam'
            ? 20 * (this.questions.length || 10)
            : (this.quiz.exam_duration || this.quiz.time_limit || this.quiz.duration || 600);
          timeTaken = initialTime - this.timeLeft;
        }
        
        if (this.mode === 'instant') {
          const currentQ = this.questions[this.currentIndex];
          if (currentQ && !(currentQ.id in this.answers)) {
            this.answers[currentQ.id] = null;
          }
        }

        const reachedQuestions = (this.mode === 'instant')
          ? this.questions.slice(0, this.currentIndex + 1)
          : this.questions;

        const payload = {
          quiz_id:              this.quiz.id,
          mode:                 this.mode || 'exam',
          player_name:          this.playerName || undefined,
          question_ids:         this.questions.map(q => q.id),
          reached_question_ids: reachedQuestions.map(q => q.id),
          answers: Object.entries(this.answers).map(([question_id, option_id]) => ({
            question_id: parseInt(question_id),
            option_id:   option_id ? parseInt(option_id) : null,
          })),
          time_taken: timeTaken,
          snapshots:  this.snapshots || []
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

        // Navigasi ke result, sertakan challenge ID dan assignment ID jika ada
        // Build result URL — sertakan mode, challenge ID, dan assign sebagai query params
        let resultHash = `#/result/${result.attempt_id}`;
        const qp = [];
        if (this.mode && this.mode !== 'exam') qp.push(`mode=${this.mode}`);
        if (this.challengeId) qp.push(`cid=${this.challengeId}`);
        if (this.assignmentId) qp.push(`assign=${this.assignmentId}`);
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
      if (score >= 60) return { label: 'Cukup Baik!', emoji: '👍', cls: 'text-blue-500'   };
      return { label: 'Perlu Belajar Lagi', emoji: '📚', cls: 'text-red-500' };
    },

    // ============================================================
    // AUDIO SYSTEM — Web Audio API (no external files)
    // ============================================================

    /** Apakah mode ini menggunakan suara? */
    get _soundEnabled() {
      return this.mode === 'instant' || this.mode === 'end' || this.mode === 'challenge';
    },

    /** Inisialisasi AudioContext dan langsung resume (wajib setelah user gesture) */
    _initAC() {
      if (this._ac) {
        if (this._ac.state === 'suspended') this._ac.resume();
        return;
      }
      try {
        this._ac = new (window.AudioContext || window.webkitAudioContext)();
        this._ac.resume(); // wajib agar tidak stuck di 'suspended'
      } catch (_) {}
    },

    /** Mulai pola "ting-tung" memacu adrenalin */
    startBgMusic() {
      if (!this._soundEnabled) return;
      this._initAC();
      const ctx = this._ac;
      if (!ctx) return;
      this.stopBgMusic();

      // Master gain — langsung masuk tanpa fade panjang
      const master = ctx.createGain();
      master.gain.setValueAtTime(0, ctx.currentTime);
      master.gain.linearRampToValueAtTime(0.55, ctx.currentTime + 0.3);
      master.connect(ctx.destination);
      this._bgGain = master;

      // Jadwalkan pola ting-tung
      this._scheduleHb();
    },

    /**
     * Scheduler pulse meditasi — look-ahead 1.5 detik, interval 250ms
     * Pulse lembut dengan interval yang lebih lambat (100 BPM) untuk kesan fokus dan tenang
     * Pulse1 = G3 (196 Hz) sine — nada dasar yang stabil, menenangkan
     * Pulse2 = D4 (293.66 Hz) sine — quint yang sempurna, harmonis
     */
    _scheduleHb() {
      if (!this._bgGain) return;
      const ctx      = this._ac;
      const BEAT     = 0.60;   // detik per ketukan (~100 BPM) — lebih lambat, lebih tenang
      const LOOKAHEAD = 1.5;   // jadwalkan sejauh ini ke depan
      const INTERVAL  = 250;   // ms antar panggilan scheduler

      // Inisialisasi pointer waktu pertama kali
      if (!this._nextBeatTime) this._nextBeatTime = ctx.currentTime + 0.05;

      let beatCount = this._beatCount || 0;

      const scheduleNote = (when, isPulse1) => {
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        
        // Selalu gunakan sine wave untuk suara yang smooth dan lembut
        osc.type = 'sine';

        if (isPulse1) {
          // PULSE 1 — G3 (196 Hz) — bass yang stabil dan menenangkan
          osc.frequency.setValueAtTime(196, when);
          gain.gain.setValueAtTime(0, when);
          gain.gain.linearRampToValueAtTime(0.12, when + 0.08);        // Onset yang sangat gentle
          gain.gain.exponentialRampToValueAtTime(0.001, when + 0.55);  // Decay yang panjang untuk resonansi
        } else {
          // PULSE 2 — D4 (293.66 Hz) — quint yang harmonis, memberikan "lift" positif
          osc.frequency.setValueAtTime(293.66, when);
          osc.frequency.linearRampToValueAtTime(196, when + 0.4);      // Smooth pitch glide ke bass
          gain.gain.setValueAtTime(0, when);
          gain.gain.linearRampToValueAtTime(0.10, when + 0.10);        // Sedikit lebih lambat onset
          gain.gain.exponentialRampToValueAtTime(0.001, when + 0.60);  // Decay lebih lama
        }

        osc.connect(gain);
        gain.connect(this._bgGain);
        osc.start(when);
        osc.stop(when + 0.65);
      };

      const tick = () => {
        if (!this._bgGain) return;

        // Jadwalkan semua pulse yang masuk dalam jendela lookahead
        while (this._nextBeatTime < ctx.currentTime + LOOKAHEAD) {
          const isPulse1 = (this._beatCount % 2 === 0);
          scheduleNote(this._nextBeatTime, isPulse1);
          this._beatCount++;
          this._nextBeatTime += BEAT;
        }

        this._hbTimeout = setTimeout(tick, INTERVAL);
      };

      this._beatCount    = 0;
      this._nextBeatTime = ctx.currentTime + 0.05;
      tick();
    },

    /** Hentikan ting-tung dengan fade out cepat */
    stopBgMusic() {
      clearTimeout(this._hbTimeout);
      this._hbTimeout    = null;
      this._nextBeatTime = 0;
      this._beatCount    = 0;
      if (!this._bgGain || !this._ac) return;
      const g = this._bgGain;
      try {
        g.gain.cancelScheduledValues(this._ac.currentTime);
        g.gain.setValueAtTime(g.gain.value, this._ac.currentTime);
        g.gain.linearRampToValueAtTime(0, this._ac.currentTime + 0.15);
      } catch (_) {}
      this._bgNodes = [];
      this._bgGain  = null;
    },

    /** Suara benar: harmoni yang hangat dan menenangkan — F major chord */
    playCorrect() {
      if (!this._soundEnabled) return;
      this._initAC();
      const ctx = this._ac;
      if (!ctx) return;
      
      // F major chord: F (349.2), A (440), C (261.6) — warm, positive, calming
      const freqs = [261.6, 349.2, 440];
      freqs.forEach((freq, i) => {
        const t    = ctx.currentTime + i * 0.08;  // Sedikit lebih lambat untuk kesan lembut
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type            = 'sine';
        osc.frequency.value = freq;
        
        // Envelope yang lebih halus dan lembut — ideal untuk menenangkan
        gain.gain.setValueAtTime(0, t);
        gain.gain.linearRampToValueAtTime(0.25, t + 0.06);      // Naik lebih lambat (gentle)
        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.55); // Lebih panjang untuk resonansi
        
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start(t); osc.stop(t + 0.6);
      });
    },

    /** Suara salah: 2-nada yang lembut dan jelas — bukan harsh, tapi informatif */
    playWrong() {
      if (!this._soundEnabled) return;
      this._initAC();
      const ctx = this._ac;
      if (!ctx) return;

      // Nada pertama: A4 (440 Hz) — nada yang jelas tapi lembut
      const osc1  = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(440, ctx.currentTime);
      osc1.frequency.linearRampToValueAtTime(220, ctx.currentTime + 0.35); // Turun ke A3 — smooth
      gain1.gain.setValueAtTime(0, ctx.currentTime);
      gain1.gain.linearRampToValueAtTime(0.20, ctx.currentTime + 0.05);     // Onset yang lembut
      gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45); // Decay yang smooth
      osc1.connect(gain1); gain1.connect(ctx.destination);
      osc1.start(); osc1.stop(ctx.currentTime + 0.5);

      // Nada kedua (harmonik): E3 (164.8 Hz) — resonansi dalam yang menenangkan, bukan mengganggu
      const osc2  = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(164.8, ctx.currentTime + 0.1);  // Start setelah nada pertama
      osc2.frequency.linearRampToValueAtTime(110, ctx.currentTime + 0.45);   // Turun ke A2
      gain2.gain.setValueAtTime(0, ctx.currentTime + 0.1);
      gain2.gain.linearRampToValueAtTime(0.15, ctx.currentTime + 0.15);      // Lebih lembut dari osc1
      gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.55);
      osc2.connect(gain2); gain2.connect(ctx.destination);
      osc2.start(ctx.currentTime + 0.1); osc2.stop(ctx.currentTime + 0.6);
    },

    destroy() {
      this.stopTimer();
      this.stopQuestionTimer();
      this._stopHeartbeat();
      this.stopBgMusic();
    },
  };
}
