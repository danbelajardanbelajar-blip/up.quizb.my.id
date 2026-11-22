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
    mode: 'exam',         // exam | instant | end | challenge | tournament
    challengeId: null,    // untuk mode challenge
    tournamentId: null,   // untuk mode tournament
    heartbeatInterval: null,
    user: null,            // data user yang sedang login
    playerName: '',        // nama tamu (dari localStorage, opsional)
    questionTimeLeft: 0,    // timer per soal (instant/end mode)
    participants: [],       // { name, score, disconnected, ... }
    myScore: 0,
    syncInterval: null,
    syncCountdown: 0,
    questionTimerInterval: null,
    questionTimerDefault: 20, // detik per soal
    
    // Proctoring & Face Verification
    cameraStream: null,
    snapshotInterval: null,
    scanStatusMessage: '',
    scanDirection: '',
    scanGuideColor: '',
    scanCountdown: 0,
    scanCaptured: false,
    _scanInterval: null,
    _initialCheckedIn: false,

    // Audio Proctoring (Crowd / Noise Detection)
    _audioCtx: null,
    _audioAnalyser: null,
    _audioData: null,
    _audioInterval: null,
    _sustainedNoiseDuration: 0,
    isNoiseFrozen: false,
    noiseFreezeCountdown: 0,
    _noiseFreezeTimer: null,

    // Fullscreen Monitoring
    isFullscreenExited: false,
    _fullscreenListener: null,
    _visibilityListener: null,

    // Real-Time Periodic Face Warning & Fullscreen Screen Freeze
    showFaceWarning: false,
    faceWarningType: '', // 'absence' | 'mismatch'
    faceWarningText: '',
    faceAbsenceCounter: 0,
    _fastFaceCheckInterval: null,

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
    get isReviewMode() { return this.mode === 'instant' || this.mode === 'end' || this.mode === 'challenge' || this.mode === 'tournament'; },
    get modeLabel() {
      return { exam: '🎯 Mode Ujian', instant: '⚡ Instant Review', end: '📖 End Review', challenge: '⚔️ Tantangan', tournament: '🏆 Turnamen' }[this.mode] || '';
    },
    get modeBadgeClass() {
      return {
        exam:       'bg-blue-100   text-blue-700   dark:bg-blue-900/40   dark:text-blue-300',
        instant:    'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
        end:        'bg-green-100  text-green-700  dark:bg-green-900/40  dark:text-green-300',
        challenge:  'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
        tournament: 'bg-amber-100  text-amber-800  dark:bg-amber-900/40  dark:text-amber-300',
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
      // Ambil user saat ini
      if (window._quizbUser) {
        this.user = window._quizbUser;
      } else {
        try {
          const appData = document.querySelector('[x-data*="QuizBApp"]')?._x_dataStack?.[0];
          if (appData?.user) {
            this.user = appData.user;
            window._quizbUser = appData.user;
          }
        } catch (_) {}
      }

      this.$watch('currentIndex', () => {
        if (this.isReviewMode && this.phase === 'playing') {
          this.startQuestionTimer();
        }
      });
    },

    // ---- Load quiz ----
    async loadQuiz(quizId, assignmentId = null, mode = 'exam', challengeId = null, tournamentId = null) {
      if (this.cameraStream) this.stopCamera();
      this.startTimeMs=0; this.result=null;
      this._proctorSession=''; this._proctorNonce='';
      this.phase = 'loading';
      this.error = null;
      this.assignmentId = assignmentId;
      this.mode         = mode || 'exam';
      this.challengeId  = challengeId ? parseInt(challengeId) : null;
      this.tournamentId = tournamentId ? parseInt(tournamentId) : null;
      try {
        const params = { id: quizId, mode: this.mode, camera_preview: 1 };
        if (assignmentId) params.assignment_id = assignmentId;
        if (this.mode === 'challenge' && this.challengeId) params.challenge_id = this.challengeId;
        if (this.mode === 'tournament' && this.tournamentId) params.tournament_id = this.tournamentId;
        const data = await api.get('quiz.questions', params);
        this._questionLoadParams={...params}; delete this._questionLoadParams.camera_preview;
        this._cameraQuestionsPending=data.camera_verification_required===true;
        this.quiz      = data.quiz;
        this.questions = data.questions || [];
        // Initialize participants array (will be populated by challenge.sync or tournament.sync)
        if ((this.mode === 'challenge' && this.challengeId) || (this.mode === 'tournament' && this.tournamentId)) {
          this.participants = [];
        }

        // Untuk mode EXAM: 20 detik per soal × jumlah soal
        // Untuk mode lain (challenge, dll): gunakan exam_duration/time_limit sebagai fallback
        if (this.mode === 'exam') {
          const SECS_PER_QUESTION = 20;
          this.timeLeft = SECS_PER_QUESTION * (data.question_count || this.questions.length || 10);
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
        this.phase = 'ready';
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
      // 1. Fullscreen Enforcement (dipanggil langsung dalam user click gesture)
      this.requestFullscreenMode();

      if (this.quiz?.require_camera == 1) {
        if (this._cameraStarting) return;
        this._cameraStarting = true;
        if (this.cameraStream) this.stopCamera();
        const generation=++this._cameraGeneration;
        try {
          if (!window.QuizFaceVerifier) throw Error('Modul kamera belum tersedia. Muat ulang halaman.');
          await window.QuizFaceVerifier.initCascade();
          if (generation!==this._cameraGeneration) return;
          let stream;
          try { stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:true}); }
          catch (_) { if (generation!==this._cameraGeneration) return; stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false}); }
          if (generation!==this._cameraGeneration) { stream.getTracks().forEach(track=>track.stop()); return; }
          this.cameraStream=stream;
          this._proctorSession = ''; this._proctorNonce = ''; this._lastServerVerified = 0;
          this._attachCameraTracks();
          const reference = await api.get('proctoring.check_reference',{}, {signal:AbortSignal.timeout(15000)});
          if (generation!==this._cameraGeneration) return;
          if (reference?.protocol !== 2 || typeof reference.has_reference !== 'boolean') throw Error('Layanan kamera perlu diperbarui. Muat ulang atau hubungi pengajar.');
          this.startHandsFreeFaceScan(!reference.has_reference);
        } catch (error) {
          if (generation!==this._cameraGeneration) return;
          this.stopCamera(); this.stopTimer(); this.stopQuestionTimer();
          this.error = error.message || 'Kamera atau layanan verifikasi belum tersedia. Jawaban Anda tetap tersimpan di halaman ini.';
          this.phase = 'camera_error';
        } finally { this._cameraStarting = false; }
        return;
      }

      this.startActualQuiz();
    },

    // ---- Mulai pengerjaan soal aktual ----
    startActualQuiz() {
      this.phase = 'playing';
      const resuming = !!this.startTimeMs;
      if (!resuming) this.startTimeMs = Date.now();
      this.startBgMusic();           // 🔊 musik latar mulai
      if (this.isReviewMode) {
        this.startQuestionTimer(resuming);
      } else {
        this.startTimer();
      }
      if (this.assignmentId) this._startHeartbeat();
      if (this.mode === 'tournament' && this.tournamentId) this.startTournamentSync();

      // Aktifkan pemantauan berkala jika require_camera
      if (this.quiz?.require_camera == 1) {
        ((this.$nextTick || (cb => setTimeout(cb, 20))).bind(this))(() => {
          const pipVideo = (this.$refs && this.$refs.proctorPipVideo) || (typeof document !== 'undefined' && typeof document.getElementById === 'function' ? document.getElementById('proctorPipVideo') : null);
          if (pipVideo && this.cameraStream) {
            pipVideo.srcObject = this.cameraStream;
            pipVideo.play().catch(() => {});
          }
        });
        this.startSnapshotLoop();
        this.startProctoringAudio();
        this.initFullscreenListeners();
      }
    },

    // ---- Camera verification: presence in browser; identity/challenge on server ----
    _cameraGeneration: 0,
    _proctorSession: '',
    _proctorNonce: '',
    _vpsMotionRequired: false,
    _vpsDirection: '',
    _lastServerVerified: 0,
    _proctorBusy: false,
    _cameraStarting: false,
    _scanBusy: false,
    _cameraTrackListeners: [],

    _attachCameraTracks() {
      for (const track of this.cameraStream?.getVideoTracks?.() || []) {
        const stop = () => {
          if (this._stoppingCamera || !['playing','face_scan'].includes(this.phase)) return;
          this._lastServerVerified = 0;
          this.showFaceWarning = true; this.faceWarningType = 'camera';
          this.faceWarningText = 'Kamera terputus atau berhenti mengirim gambar. Sambungkan kembali kamera.';
          this.stopTimer(); this.stopQuestionTimer(); this.stopCamera();
          this.error = this.faceWarningText; this.phase = 'camera_error';
        };
        track.addEventListener('ended', stop); track.addEventListener('mute', stop);
        this._cameraTrackListeners.push({track, stop});
      }
    },

    async _cameraVideo(preferred) {
      const tracks = this.cameraStream?.getVideoTracks?.() || [];
      if (!tracks.length || tracks.some(track => track.readyState !== 'live' || !track.enabled || track.muted)) throw Error('Kamera belum aktif. Sambungkan kembali kamera.');
      let video = preferred || this.$refs?.proctorPipVideo || this._proctorVideo;
      if (!video) { video = document.createElement('video'); video.muted = true; video.playsInline = true; }
      if (video.srcObject !== this.cameraStream) { video.srcObject = this.cameraStream; await video.play(); }
      if (!video.videoWidth || video.readyState < 2) {
        await new Promise((resolve,reject) => {
          const timeout = setTimeout(() => { video.removeEventListener('loadeddata',ready); reject(Error('Kamera belum menghasilkan gambar. Coba sambungkan ulang.')); },5000);
          const ready = () => { clearTimeout(timeout); video.removeEventListener('loadeddata',ready); resolve(); };
          video.addEventListener('loadeddata',ready,{once:true});
        });
      }
      if (!video.videoWidth || !video.videoHeight || video.readyState < 2) throw Error('Gambar kamera belum siap.');
      this._proctorVideo = video;
      return video;
    },

    async _verifyCamera(video, final = false) {
      if (this._proctorBusy) return {verified:false,status:'BUSY',message:'Menunggu pemeriksaan kamera sebelumnya…'};
      this._proctorBusy = true;
      const generation = this._cameraGeneration;
      try {
        if (!this._proctorSession) {
          const begin = await api.post('proctoring.begin',{quiz_id:this.quiz.id,assignment_id:Number(this.assignmentId)||0},{signal:AbortSignal.timeout(18000)});
          if (generation !== this._cameraGeneration) return {verified:false,status:'CANCELLED'};
          if (!/^[a-f0-9]{64}$/.test(begin.session || '') || !/^[a-f0-9]{64}$/.test(begin.nonce || '')) throw Error('Sesi kamera tidak valid. Muat ulang layanan.');
          this._proctorSession = begin.session; this._proctorNonce = begin.nonce;
          this._vpsMotionRequired=begin.capture_protocol==='pose-burst-v2';
          this._vpsDirection=begin.direction;
          if (this._vpsMotionRequired && !['left','right'].includes(begin.direction)) throw Error('Arah tantangan server tidak valid.');
        }
        const frames=this._vpsMotionRequired ? await this._captureVpsMotion(video,generation) : undefined;
        const snapshot = frames ? frames[frames.length-1] : window.QuizFaceVerifier.captureSnapshot(video);
        const result = await api.post('proctoring.verify',{session:this._proctorSession,nonce:this._proctorNonce,snapshot,frames,purpose:final?'final':'periodic'},{signal:AbortSignal.timeout(18000)});
        if (generation !== this._cameraGeneration) return {verified:false,status:'CANCELLED'};
        if (!/^[a-f0-9]{64}$/.test(result.next_nonce || '')) throw Error('Respons verifikasi kamera tidak valid. Coba kembali.');
        this._proctorNonce = result.next_nonce;
        if (result.verified === true && result.status === 'VERIFIED') { this._lastServerVerified = Date.now(); this._vpsMotionRequired=false; }
        else this._lastServerVerified = 0;
        return result;
      } catch (error) {
        if (generation === this._cameraGeneration) { this._proctorSession = ''; this._proctorNonce = ''; this._lastServerVerified = 0; }
        throw error;
      } finally { this._proctorBusy = false; }
    },

    async _captureVpsMotion(video,generation) {
      const frames=[];
      // The preview is mirrored; the server evaluates original camera pixels.
      const displayDirection=this._vpsDirection==='left'?'right':'left';
      const side=displayDirection==='left'?'kiri':'kanan';
      const instructions=['Hadapkan wajah lurus ke kamera.',`Tolehkan kepala perlahan ke ${side} layar, lalu tahan.`, 'Kembali hadapkan wajah lurus seperti posisi awal.'];
      try {
      for (let stage=0;stage<3;stage++) {
        this.scanDirection=stage===1 ? displayDirection : '';
        for (let sample=0;sample<3;sample++) {
          if (generation!==this._cameraGeneration) throw Error('Pemeriksaan kamera dibatalkan.');
          const message=`Langkah ${stage+1}/3: ${instructions[stage]} (${3-sample})`;
          this.scanStatusMessage=message;this.faceWarningText=message;this.scanCountdown=3-sample;
          await new Promise(resolve=>setTimeout(resolve,1000));
          if (generation!==this._cameraGeneration) throw Error('Pemeriksaan kamera dibatalkan.');
          await this._cameraVideo(video);
          const state=window.QuizFaceVerifier.evaluateFrame(video);
          if (!state.ready) throw Error(state.label || 'Wajah belum terlihat jelas. Ulangi gerakan.');
          frames.push(window.QuizFaceVerifier.captureSnapshot(video));
        }
      }
      this.scanCountdown=0;this.scanStatusMessage='Memeriksa sembilan foto tantangan di server…';
      return frames;
      } finally { if (generation===this._cameraGeneration) this.scanDirection=''; }
    },

    _blockCamera(message, type = 'verification') {
      this.showFaceWarning = true; this.faceWarningType = type;
      this.faceWarningText = message || 'Verifikasi kamera belum berhasil. Coba kembali atau hubungi pengajar.';
      this._lastServerVerified = 0;
      this.startFastFaceCheck();
      this.$nextTick?.(() => {
        const video = this.$refs?.proctorFreezeVideo;
        if (video && this.cameraStream) { video.srcObject = this.cameraStream; video.play().catch(() => {}); }
      });
    },

    startHandsFreeFaceScan(enrolling = false) {
      this.phase = 'face_scan'; this.scanCaptured = false; this.scanCountdown = 0;
      this.scanStatusMessage = enrolling ? 'Hadapkan satu wajah ke kamera untuk foto acuan.' : 'Memulai verifikasi kamera terhadap foto acuan…';
      this.scanGuideColor = ''; this._scanBusy = false;
      const generation = this._cameraGeneration;
      (this.$nextTick || (callback => setTimeout(callback,20)))(async () => {
        let video;
        try { video = await this._cameraVideo(this.$refs?.faceScanVideo || document.getElementById('faceScanVideo')); }
        catch (error) { if (generation !== this._cameraGeneration) return; this.error = error.message; this.phase = 'camera_error'; this.stopCamera(); return; }
        let stableSince = 0;
        if (this._scanInterval) clearInterval(this._scanInterval);
        this._scanInterval = setInterval(async () => {
          if (generation !== this._cameraGeneration || this.phase !== 'face_scan' || this._scanBusy) return;
          this._scanBusy = true;
          try {
            const frame = window.QuizFaceVerifier.evaluateFrame(video);
            if (!frame.ready) { stableSince=0; this.scanCountdown=0; this.scanGuideColor=frame.color||'amber'; this.scanStatusMessage=frame.label; return; }
            this.scanGuideColor='green';
            if (enrolling) {
              if (!stableSince) stableSince=Date.now();
              this.scanCountdown=Math.max(1,Math.ceil((2000-(Date.now()-stableSince))/1000));
              if (Date.now()-stableSince<2000) { this.scanStatusMessage='Tahan posisi untuk foto acuan…'; return; }
              const saved=await api.post('proctoring.save_reference',{photo:window.QuizFaceVerifier.captureSnapshot(video)});
              if (saved.success !== true) throw Error(saved.message || 'Foto acuan belum tersimpan.');
              if (generation !== this._cameraGeneration || this.phase !== 'face_scan') return;
              enrolling=false; this.scanCaptured=true; this.scanCountdown=0;
            }
            const result=await this._verifyCamera(video);
            if (generation !== this._cameraGeneration || this.phase !== 'face_scan') return;
            this.scanStatusMessage=result.message; this.scanGuideColor=result.verified?'green':'amber';
            if (result.verified === true && result.status === 'VERIFIED') {
              clearInterval(this._scanInterval); this._scanInterval=null;
              if (this._cameraQuestionsPending) {
                const data=await api.get('quiz.questions',this._questionLoadParams || {id:this.quiz.id,assignment_id:this.assignmentId || 0,mode:this.mode});
                if (generation !== this._cameraGeneration || this.phase !== 'face_scan') return;
                if (data.camera_verification_required || !Array.isArray(data.questions) || !data.questions.length) throw Error('Soal belum dapat dibuka. Verifikasi kamera ulang.');
                this.questions=data.questions; this._cameraQuestionsPending=false;
              }
              this.showFaceWarning=false; this.faceWarningType=''; this.startActualQuiz();
            }
          } catch (error) {
            if (generation !== this._cameraGeneration) return;
            this.scanStatusMessage=error.message; this.scanGuideColor='red'; stableSince=0;
          } finally { this._scanBusy=false; }
        },1000);
      });
    },

    async captureCurrentSnapshot() {
      const video=await this._cameraVideo();
      return window.QuizFaceVerifier.captureSnapshot(video);
    },

    // ---- Fullscreen Enforcement & Listeners ----
    requestFullscreenMode() {
      try {
        const elem = document.documentElement;
        if (elem.requestFullscreen) {
          elem.requestFullscreen().catch(() => {});
        } else if (elem.webkitRequestFullscreen) {
          elem.webkitRequestFullscreen();
        }
      } catch (_) {}
    },

    reEnterFullscreen() {
      this.isFullscreenExited = false;
      this.requestFullscreenMode();
    },

    initFullscreenListeners() {
      this.removeFullscreenListeners();

      this._fullscreenListener = () => {
        if (this.phase !== 'playing') return;
        const isFull = !!(document.fullscreenElement || document.webkitFullscreenElement);
        if (!isFull) {
          this.isFullscreenExited = true;
          this.captureCurrentSnapshot().then(snap => {
            api.post('proctoring.log_event', {
              quiz_id: this.quiz.id,
              assignment_id: this.assignmentId || 0,
              attempt_id: this.attemptId || null,
              event_type: 'fullscreen_violation',
              status: 'VIOLATION',
              snapshot: snap,
              notes: 'Keluar dari mode layar penuh (Full Screen)'
            }).catch(() => {});
          });
        } else {
          this.isFullscreenExited = false;
        }
      };

      this._visibilityListener = () => {
        if (this.phase !== 'playing') return;
        if (document.hidden) {
          this.captureCurrentSnapshot().then(snap => {
            api.post('proctoring.log_event', {
              quiz_id: this.quiz.id,
              assignment_id: this.assignmentId || 0,
              attempt_id: this.attemptId || null,
              event_type: 'tab_switch_violation',
              status: 'VIOLATION',
              snapshot: snap,
              notes: 'Berpindah tab atau aplikasi lain saat ujian berlangsung'
            }).catch(() => {});
          });
        }
      };

      document.addEventListener('fullscreenchange', this._fullscreenListener);
      document.addEventListener('webkitfullscreenchange', this._fullscreenListener);
      document.addEventListener('visibilitychange', this._visibilityListener);
    },

    removeFullscreenListeners() {
      if (this._fullscreenListener) {
        document.removeEventListener('fullscreenchange', this._fullscreenListener);
        document.removeEventListener('webkitfullscreenchange', this._fullscreenListener);
        this._fullscreenListener = null;
      }
      if (this._visibilityListener) {
        document.removeEventListener('visibilitychange', this._visibilityListener);
        this._visibilityListener = null;
      }
    },

    // ---- Web Audio Ambient Noise Monitoring ----
    startProctoringAudio() {
      if (!this.cameraStream || this.cameraStream.getAudioTracks().length === 0) return;

      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;

        this._audioCtx = new AudioCtx();
        const source = this._audioCtx.createMediaStreamSource(this.cameraStream);
        const analyser = this._audioCtx.createAnalyser();
        analyser.fftSize = 512;
        analyser.smoothingTimeConstant = 0.4;
        source.connect(analyser);

        this._audioAnalyser = analyser;
        this._audioData = new Uint8Array(analyser.frequencyBinCount);
        this._sustainedNoiseDuration = 0;

        if (this._audioInterval) clearInterval(this._audioInterval);
        this._audioInterval = setInterval(() => this.checkAmbientNoise(), 150);
      } catch (err) {
        console.warn('[Proctoring Audio Init Warning]', err);
      }
    },

    checkAmbientNoise() {
      if (this.phase !== 'playing' || this.isNoiseFrozen || !this._audioAnalyser) return;

      this._audioAnalyser.getByteFrequencyData(this._audioData);

      // Frekuensi vokal / bicara manusia: ~250 Hz - 3400 Hz (bin 3 s/d bin 40 pada fftSize 512)
      let sum = 0, count = 0;
      for (let i = 3; i <= 40; i++) {
        sum += this._audioData[i];
        count++;
      }
      const avgVoiceVolume = count > 0 ? (sum / count) : 0;

      // Ambang batas percakapan / keramaian: rata-rata volume spektrum vokal > 75
      if (avgVoiceVolume > 75) {
        this._sustainedNoiseDuration += 150;
        if (this._sustainedNoiseDuration >= 1650) {
          // Terdeteksi suara obrolan / keramaian berlanjut > 1.6 detik -> Hukuman Layar Dibekukan & Soal Ditukar!
          this._sustainedNoiseDuration = 0;
          this.triggerNoisePenalty();
        }
      } else {
        this._sustainedNoiseDuration = Math.max(0, this._sustainedNoiseDuration - 100);
      }
    },

    async triggerNoisePenalty() {
      if (this.isNoiseFrozen) return;

      this.isNoiseFrozen = true;
      this.noiseFreezeCountdown = 15;

      // Ambil snapshot bukti pelanggaran
      const snapB64 = await this.captureCurrentSnapshot();

      // Reset jawaban soal saat ini
      const currentQId = this.current?.id;
      if (currentQId && this.answers[currentQId] !== undefined) {
        delete this.answers[currentQId];
      }

      // Tukar soal melalui backend API
      try {
        const swapRes = await api.post('proctoring.swap_question', {
          quiz_id: this.quiz?.id || 0,
          assignment_id: this.assignmentId || 0,
          current_question_id: currentQId,
          exclude_question_ids: this.questions.map(q => q.id),
          snapshot: snapB64,
          notes: 'Pelanggaran suara keramaian / obrolan terdeteksi (> 1.6s)'
        });

        if (swapRes && swapRes.swapped && swapRes.new_question) {
          // Ganti soal pada indeks saat ini dengan soal baru
          this.questions[this.currentIndex] = swapRes.new_question;
        }
      } catch (swapErr) {
        console.warn('Swap question API warning:', swapErr);
      }

      // Countdown pembekuan layar (15 detik)
      if (this._noiseFreezeTimer) clearInterval(this._noiseFreezeTimer);
      this._noiseFreezeTimer = setInterval(() => {
        if (this.noiseFreezeCountdown > 1) {
          this.noiseFreezeCountdown--;
        } else {
          clearInterval(this._noiseFreezeTimer);
          this._noiseFreezeTimer = null;
          this.noiseFreezeCountdown = 0;
          this.isNoiseFrozen = false;
        }
      }, 1000);
    },

    // ---- Navigasi Kembali ke Halaman Sebelumnya ----
    exitToPreviousPage() {
      let targetFallback = '';
      if (this.tournamentId) {
        targetFallback = '#/tournament/' + this.tournamentId;
      } else if (this.assignment && this.assignment.class_id) {
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
      }, 5000); // 5 detik (turun dari 5 menit sesuai permintaan)
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

    // ---- Tournament Sync ----
    startTournamentSync() {
      if (this.syncInterval) clearInterval(this.syncInterval);

      const doSync = async () => {
        if (this.phase !== 'playing') return;
        const myUid = this.user?.id || window._quizbUser?.id || (window.Alpine && document.querySelector('[x-data*="QuizBApp"]')?._x_dataStack?.[0]?.user?.id) || 0;
        try {
          const res = await api.post('tournament.sync', {
            tournament_id: this.tournamentId,
            score: this.myScore,
            live_score: this.myScore,
            q_index: this.currentIndex
          });
          if (res && res.participants) {
            this.participants = res.participants.map(p => {
              const isMe = (p.user_id == myUid);
              const scoreVal = isMe
                ? this.myScore
                : (p.score_final !== null && p.score_final !== undefined ? Number(p.score_final) : (p.live_score ?? 0));
              return {
                id: p.user_id,
                name: p.name,
                is_me: isMe,
                score: scoreVal,
                q_index: p.q_index ?? 0,
                submitted: p.is_submitted || false,
                disconnected: p.disconnected || false
              };
            });
          }
        } catch(e) {}
      };

      // Jalankan sync langsung saat start agar lawan langsung muncul
      doSync();
      // Polling setiap 1.5 detik agar realtime & responsif
      this.syncInterval = setInterval(doSync, 1500);
    },
    
    async takeAndUploadSnapshot() {
      if (this.phase !== 'playing' || this._finalizingSubmit) return;
      if (this._proctorBusy && (!this._lastServerVerified || Date.now()-this._lastServerVerified>=20000)) this._blockCamera('Menunggu pemeriksaan server. Waktu dijeda sementara.');
      try {
        const video=await this._cameraVideo();
        const frame=window.QuizFaceVerifier.evaluateFrame(video);
        if (!frame.ready) { this._blockCamera(frame.label,'absence'); return; }
        const image=window.QuizFaceVerifier.captureSnapshot(video);
        this.snapshots=(this.snapshots || []).concat(image).slice(-30);
        if (Date.now()-this._lastServerVerified>=10000) {
          const result=await this._verifyCamera(video);
          if (this.phase !== 'playing') return;
          if (result.status === 'BUSY' || result.status === 'CANCELLED') return;
          if (result.verified !== true || result.status !== 'VERIFIED') { this._blockCamera(result.message); return; }
        }
        if (this._lastServerVerified && Date.now()-this._lastServerVerified<20000) {
          this.showFaceWarning=false; this.faceWarningType=''; this.faceAbsenceCounter=0; this.stopFastFaceCheck();
        }
      } catch (error) { if (this.phase==='playing') this._blockCamera(error.message,'camera'); }
    },

    startFastFaceCheck() {
      if (this._fastFaceCheckInterval) return;
      let checking=false;
      this._fastFaceCheckInterval=setInterval(async () => {
        if (!this.showFaceWarning || this.phase!=='playing') { this.stopFastFaceCheck(); return; }
        if (checking || this._proctorBusy || this._finalizingSubmit) return;
        checking=true;
        try {
          const video=await this._cameraVideo();
          const frame=window.QuizFaceVerifier.evaluateFrame(video);
          if (!frame.ready) { this.faceWarningText=frame.label; return; }
          const result=await this._verifyCamera(video);
          if (this.phase!=='playing') return;
          this.faceWarningText=result.message;
          if (result.verified === true && result.status === 'VERIFIED') { this.showFaceWarning=false; this.faceWarningType=''; this.stopFastFaceCheck(); if (this._pendingCameraSubmit) { this._pendingCameraSubmit=false; await this.submitAnswers(); } }
        } catch (error) { this.faceWarningText=error.message; }
        finally { checking=false; }
      },1500);
    },

    stopFastFaceCheck() {
      if (this._fastFaceCheckInterval) {
        clearInterval(this._fastFaceCheckInterval);
        this._fastFaceCheckInterval = null;
      }
    },
    
    stopCamera() {
      this._stoppingCamera=true; this._cameraGeneration++; this.scanDirection='';
      for (const {track,stop} of this._cameraTrackListeners || []) { track.removeEventListener('ended',stop); track.removeEventListener('mute',stop); }
      this._cameraTrackListeners=[];
      const scanVideo=this.$refs?.faceScanVideo;
      if (scanVideo) { scanVideo.pause(); scanVideo.srcObject=null; }

      if (this.snapshotInterval) clearInterval(this.snapshotInterval);
      this.stopFastFaceCheck();
      const pipVideo = (this.$refs && this.$refs.proctorPipVideo) || (typeof document !== 'undefined' && typeof document.getElementById === 'function' ? document.getElementById('proctorPipVideo') : null);
      if (pipVideo) {
        try { pipVideo.pause(); pipVideo.srcObject = null; } catch (_) {}
      }
      const freezeVideo = (this.$refs && this.$refs.proctorFreezeVideo) || (typeof document !== 'undefined' && typeof document.getElementById === 'function' ? document.getElementById('proctorFreezeVideo') : null);
      if (freezeVideo) {
        try { freezeVideo.pause(); freezeVideo.srcObject = null; } catch (_) {}
      }
      if (this._scanInterval) clearInterval(this._scanInterval);
      if (this._audioInterval) clearInterval(this._audioInterval);
      if (this._noiseFreezeTimer) clearInterval(this._noiseFreezeTimer);
      if (this._audioCtx) {
        try { this._audioCtx.close(); } catch(e) {}
        this._audioCtx = null;
      }
      this.removeFullscreenListeners();
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
      this._stoppingCamera=false;
    },

    // ---- Timer ----
    startTimer() {
      clearInterval(this.timerInterval);
      this.timerInterval = setInterval(() => {
        if (this.isNoiseFrozen || this.showFaceWarning || this._finalizingSubmit || this.loading) return; // Waktu dijeda saat layar dibekukan (keramaian / wajah tidak terdeteksi)
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
    startQuestionTimer(resuming = false) {
      clearInterval(this.questionTimerInterval);
      if (!resuming) this.questionTimeLeft = this.questionTimerDefault;
      this.questionTimerInterval = setInterval(() => {
        if (this.isNoiseFrozen || this.showFaceWarning || this._finalizingSubmit || this.loading) return; // Waktu dijeda saat layar dibekukan (keramaian / wajah tidak terdeteksi)
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
      if (this.isNoiseFrozen || this.showFaceWarning) return; // Kunci interaksi saat layar dibekukan (wajah tidak terdeteksi / keramaian)
      this.answers[questionId] = optionId;
      
      // Hitung skor sementara (khusus challenge)
      let correct = 0;
      for (const qId in this.answers) {
        const q = this.questions.find(x => x.id == qId);
        if (q && q.correct_option_id == this.answers[qId]) correct++;
      }
      this.myScore = Math.round((correct / (this.questions.length || 1)) * 100);
      
      // Update local participants array for instant UI feedback
      const myUid = this.user?.id || window._quizbUser?.id || (window.Alpine && document.querySelector('[x-data*="QuizBApp"]')?._x_dataStack?.[0]?.user?.id) || 0;
      if ((this.mode === 'challenge' || this.mode === 'tournament') && this.participants) {
          const me = this.participants.find(p => p.id == myUid || p.is_me);
          if (me) me.score = this.myScore;
      }

      // Sync langsung ke server saat memilih jawaban pada turnamen
      if (this.mode === 'tournament' && this.tournamentId) {
        api.post('tournament.sync', {
          tournament_id: this.tournamentId,
          score: this.myScore,
          live_score: this.myScore,
          q_index: this.currentIndex
        }).then(res => {
          if (res && res.participants) {
            this.participants = res.participants.map(p => {
              const isMe = (p.user_id == myUid);
              const scoreVal = isMe
                ? this.myScore
                : (p.score_final !== null && p.score_final !== undefined ? Number(p.score_final) : (p.live_score ?? 0));
              return {
                id: p.user_id,
                name: p.name,
                is_me: isMe,
                score: scoreVal,
                q_index: p.q_index ?? 0,
                submitted: p.is_submitted || false,
                disconnected: p.disconnected || false
              };
            });
          }
        }).catch(() => {});
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

      // ---- EXAM / CHALLENGE / TOURNAMENT ---- auto-advance jika bukan soal terakhir
      if (this.currentIndex < this.questions.length - 1) {
        setTimeout(() => {
          if (this.answers[questionId] === optionId) this.next();
        }, 500);
      } else if (this.mode === 'challenge' || this.mode === 'tournament') {
        // Mode tantangan / turnamen: soal terakhir langsung auto-submit karena adu cepat
        setTimeout(() => this.submitAnswers(), 500);
      }
    },

    isSelected(questionId, optionId) {
      return this.answers[questionId] === optionId;
    },

    // ---- Navigation (hanya exam/challenge) ----
    next() {
      if (this.isNoiseFrozen || this.showFaceWarning) return;
      if (this.currentIndex < this.questions.length - 1) this.currentIndex++;
    },
    prev() {
      if (this.isReviewMode) return; // tidak boleh kembali di review mode
      if (this.isNoiseFrozen || this.showFaceWarning) return;
      if (this.currentIndex > 0) this.currentIndex--;
    },
    goTo(index) {
      if (this.isReviewMode) return;
      if (this.isNoiseFrozen || this.showFaceWarning) return;
      this.currentIndex = index;
      this.showNav = false;
    },

    toggleFlag(qId) {
      if (this.isReviewMode) return;
      if (this.isNoiseFrozen || this.showFaceWarning) return;
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
      await this.submitAnswers();
    },

    async submitAnswers() {
      if (this._finalizingSubmit || this.loading) return;
      if (this.quiz?.require_camera == 1) {
        this._finalizingSubmit=true;
        try {
          if (this._proctorBusy) throw Error('Tunggu pemeriksaan kamera selesai, lalu kumpulkan kembali.');
          const video=await this._cameraVideo();
          const frame=window.QuizFaceVerifier.evaluateFrame(video);
          if (!frame.ready) throw Error(frame.label);
          const proof=await this._verifyCamera(video,true);
          if (proof.verified !== true || proof.status !== 'VERIFIED') throw Error(proof.message);
        } catch (error) { this._pendingCameraSubmit=true; this._blockCamera(error.message); this._finalizingSubmit=false; return; }
        this._finalizingSubmit=false;
      }
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
          assignment_id:        Number(this.assignmentId) || 0,
          proctoring_session:   this._proctorSession || undefined,
          mode:                 this.mode || 'exam',
          tournament_id:        this.tournamentId ? parseInt(this.tournamentId) : undefined,
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
      this._stopHeartbeat();
      this.stopBgMusic();            // 🔇 hentikan musik saat submit
      this.stopTimer();
      this.stopQuestionTimer();
      if (this.syncInterval) {
        clearInterval(this.syncInterval);
        this.syncInterval = null;
      }
        this.stopCamera();
        this.phase   = 'submitted';

        // Jika dari tugas: auto-submit ke assignment
        if (this.assignmentId && result.attempt_id) {
          try {
            await api.post('assignment.submit', {
              assignment_id: parseInt(this.assignmentId),
              attempt_id:    result.attempt_id,
            });
          } catch (error) {
            this.error='Hasil tersimpan, tetapi pengumpulan ke tugas belum berhasil: '+error.message;
            this.phase='submission_error'; this.loading=false; return;
          }
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

        // Jika dari tournament: submit hasil ke turnamen (non-blocking di latar belakang)
        if (this.tournamentId) {
          const finalScore = result.score !== undefined ? result.score : Math.round(((result.score_percentage || ((result.correct || 0) / (result.total || 1) * 100))));
          api.post('tournament.submit', {
            tournament_id: parseInt(this.tournamentId),
            attempt_id:    result.attempt_id ? parseInt(result.attempt_id) : null,
            score_final:   finalScore,
            time_taken:    result.time_taken || timeTaken || 0,
          }).catch(() => {});

          // Tandai bahwa turnamen ini sudah disubmit di sisi browser peserta agar tidak redirect loop
          try {
            sessionStorage.setItem('tournament_submitted_' + this.tournamentId, '1');
          } catch (_) {}

          // Arahkan langsung ke papan peringkat turnamen tanpa delay
          window.location.hash = `#/tournament/${this.tournamentId}`;
          return;
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

    async retryAssignmentSubmission() {
      if (this.loading || !this.result?.attempt_id || !this.assignmentId) return;
      this.loading=true;
      try {
        await api.post('assignment.submit',{assignment_id:Number(this.assignmentId),attempt_id:this.result.attempt_id});
        this.phase='submitted';
        window.location.hash=`#/result/${this.result.attempt_id}?assign=${this.assignmentId}&mode=${this.mode}`;
      } catch (error) { this.error='Hasil tetap tersimpan. Pengumpulan tugas belum berhasil: '+error.message; }
      finally { this.loading=false; }
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
      this.stopCamera();
      if (this.syncInterval) clearInterval(this.syncInterval);
      this.stopTimer();
      this.stopQuestionTimer();
      this._stopHeartbeat();
      this.stopBgMusic();
    },
  };
}
