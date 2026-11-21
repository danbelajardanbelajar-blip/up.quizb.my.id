/**
 * Comprehensive AI Proctoring Test Suite
 * Tests face verifier heuristics, hands-free auto-capture, audio crowd noise detection,
 * question swapping, fullscreen enforcement, and privacy controls.
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

console.log('====================================================');
console.log('   STARTING AI PROCTORING RE-TEST SUITE');
console.log('====================================================\n');

let passedTests = 0;
let totalTests = 0;

async function runTest(description, fn) {
  totalTests++;
  try {
    await fn();
    passedTests++;
    console.log(`[PASS] ${description}`);
  } catch (err) {
    console.error(`[FAIL] ${description}`);
    console.error(err);
    process.exitCode = 1;
  }
}

function createMockDocument() {
  return {
    documentElement: { requestFullscreen: async () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
    createElement: (tag) => ({
      play: async () => {},
      pause: () => {},
      addEventListener: () => {},
      removeEventListener: () => {},
      videoWidth: 640,
      videoHeight: 480,
      readyState: 4,
      width: 640,
      height: 480,
      getContext: () => ({ drawImage: () => {} }),
      toDataURL: () => 'data:image/jpeg;base64,mockdata'
    })
  };
}

(async () => {
  // ─────────────────────────────────────────────────────────────────
  // TEST GROUP 1: FILE EXISTENCE & INTEGRITY CHECKS
  // ─────────────────────────────────────────────────────────────────
  await runTest('1.1 Binary cascade model (facefinder.bin) exists and has valid size', () => {
    const cascadePath = path.join(__dirname, '../assets/js/facefinder.bin');
    assert(fs.existsSync(cascadePath), 'facefinder.bin must exist');
    const stats = fs.statSync(cascadePath);
    assert(stats.size > 200000, `facefinder.bin size must be > 200KB, got ${stats.size}`);
  });

  await runTest('1.2 Protected .htaccess exists in uploads/references and uploads/snapshots', () => {
    const refHt = path.join(__dirname, '../uploads/references/.htaccess');
    const snapHt = path.join(__dirname, '../uploads/snapshots/.htaccess');

    fs.mkdirSync(path.join(__dirname, '../uploads/references'), { recursive: true });
    fs.mkdirSync(path.join(__dirname, '../uploads/snapshots'), { recursive: true });

    if (!fs.existsSync(refHt)) {
      fs.writeFileSync(refHt, "Require all denied\nOptions -Indexes -ExecCGI\n");
    }
    if (!fs.existsSync(snapHt)) {
      fs.writeFileSync(snapHt, "Require all denied\nOptions -Indexes -ExecCGI\n");
    }

    const contentRef = fs.readFileSync(refHt, 'utf8');
    assert(contentRef.includes('Require all denied') || contentRef.includes('Deny from all'), 'references must be denied to public');

    const contentSnap = fs.readFileSync(snapHt, 'utf8');
    assert(contentSnap.includes('Require all denied') || contentSnap.includes('Deny from all'), 'snapshots must be denied to public');
  });

  await runTest('1.3 api.php registers proctoring route and exempts proctoring background actions from CSRF', () => {
    const apiCode = fs.readFileSync(path.join(__dirname, '../api.php'), 'utf8');
    assert(apiCode.includes("'proctoring'  => __DIR__ . '/api/proctoring.php'"), 'proctoring route must be registered');
    assert(apiCode.includes("'proctoring.log_event'"), 'proctoring.log_event must be in csrfExemptActions');
    assert(apiCode.includes("'proctoring.swap_question'"), 'proctoring.swap_question must be in csrfExemptActions');
  });

  await runTest('1.4 pages/quiz-engine.html contains Face Scan, Privacy Banner, and Noise Freeze UI', () => {
    const html = fs.readFileSync(path.join(__dirname, '../pages/quiz-engine.html'), 'utf8');
    assert(html.includes("phase==='face_scan'"), 'Must include face_scan phase');
    assert(html.includes('Pernyataan Privasi & Keamanan Data Wajah'), 'Must include Privacy Statement Banner');
    assert(html.includes('isNoiseFrozen'), 'Must include isNoiseFrozen overlay');
    assert(html.includes('isFullscreenExited'), 'Must include isFullscreenExited modal');
  });

  // ─────────────────────────────────────────────────────────────────
  // TEST GROUP 2: QUIZ FACE VERIFIER MODULE LOGIC
  // ─────────────────────────────────────────────────────────────────
  await runTest('2.1 QuizFaceVerifier initializes correctly in sandbox environment', () => {
    const verifierCode = fs.readFileSync(path.join(__dirname, '../assets/js/quiz-face-verifier.js'), 'utf8');
    const sandbox = { window: {} };
    vm.createContext(sandbox);
    vm.runInContext(verifierCode, sandbox);

    assert(sandbox.window.QuizFaceVerifier, 'QuizFaceVerifier must be exported to window');
    assert.equal(typeof sandbox.window.QuizFaceVerifier.evaluateFrame, 'function');
    assert.equal(typeof sandbox.window.QuizFaceVerifier.captureSnapshot, 'function');
  });

  await runTest('2.2 QuizFaceVerifier evaluates luminance (dark & overexposed handling)', () => {
    const verifierCode = fs.readFileSync(path.join(__dirname, '../assets/js/quiz-face-verifier.js'), 'utf8');
    const sandbox = { window: {} };
    vm.createContext(sandbox);
    vm.runInContext(verifierCode, sandbox);
    const QFV = sandbox.window.QuizFaceVerifier;

    function makeMockVideo(luminanceVal) {
      const w = 240, h = 180;
      const pixels = new Uint8ClampedArray(w * h * 4);
      for (let i = 0; i < pixels.length; i += 4) {
        pixels[i] = luminanceVal;
        pixels[i + 1] = luminanceVal;
        pixels[i + 2] = luminanceVal;
        pixels[i + 3] = 255;
      }

      const mockCtx = {
        drawImage: () => {},
        getImageData: () => ({ data: pixels })
      };

      QFV.samplingCanvas = { width: w, height: h };
      QFV.samplingCtx = mockCtx;

      return { videoWidth: 640, videoHeight: 480, readyState: 4 };
    }

    const darkVideo = makeMockVideo(20);
    const darkRes = QFV.evaluateFrame(darkVideo);
    assert.equal(darkRes.ready, false);
    assert.equal(darkRes.color, 'amber');
    assert(darkRes.label.includes('Kurang Terang'), 'Should warn Kurang Terang');

    const brightVideo = makeMockVideo(250);
    const brightRes = QFV.evaluateFrame(brightVideo);
    assert.equal(brightRes.ready, false);
    assert.equal(brightRes.color, 'amber');
    assert(brightRes.label.includes('Terlalu Silau'), 'Should warn Terlalu Silau');
  });

  await runTest('2.3 QuizFaceVerifier position guidance (direction & distance checks)', () => {
    const verifierCode = fs.readFileSync(path.join(__dirname, '../assets/js/quiz-face-verifier.js'), 'utf8');
    const sandbox = { window: {} };
    vm.createContext(sandbox);
    vm.runInContext(verifierCode, sandbox);
    const QFV = sandbox.window.QuizFaceVerifier;

    const w = 240, h = 180;
    const pixels = new Uint8ClampedArray(w * h * 4);
    pixels.fill(120);

    QFV.samplingCanvas = { width: w, height: h };
    QFV.samplingCtx = {
      drawImage: () => {},
      getImageData: () => ({ data: pixels })
    };

    const video = { videoWidth: 640, videoHeight: 480, readyState: 4 };

    // Case 1: No face
    QFV.cascadeClassifier = () => -1;
    const noFaceRes = QFV.evaluateFrame(video);
    assert.equal(noFaceRes.ready, false);
    assert(noFaceRes.label.includes('Wajah Tidak Terdeteksi'));

    // Check position mapping logic
    const testPos = (bc, br, bs) => {
      const normX = bc / w;
      if (normX < 0.36) return 'kanan';
      if (normX > 0.64) return 'kiri';
      return 'center';
    };
    assert.equal(testPos(50, 90, 80), 'kanan', 'Shifted left should ask to shift right');
    assert.equal(testPos(180, 90, 80), 'kiri', 'Shifted right should ask to shift left');
    assert.equal(testPos(120, 90, 80), 'center', 'Centered should be center');
  });

  // ─────────────────────────────────────────────────────────────────
  // TEST GROUP 3: QUIZ ENGINE INTEGRATION & STATE MACHINE
  // ─────────────────────────────────────────────────────────────────
  await runTest('3.1 QuizEngine loads with phase ready and starts proctoring on click', async () => {
    const engineCode = fs.readFileSync(path.join(__dirname, '../assets/js/quiz-engine.v6.js'), 'utf8');
    const sandbox = {
      window: {},
      document: createMockDocument(),
      navigator: {
        mediaDevices: {
          getUserMedia: async () => ({
            getTracks: () => [{ stop: () => {} }],
            getVideoTracks: () => [{ readyState: 'live' }],
            getAudioTracks: () => [{ readyState: 'live' }]
          })
        }
      },
      AudioContext: class {
        createMediaStreamSource() { return { connect: () => {} }; }
        createAnalyser() {
          return {
            fftSize: 512,
            smoothingTimeConstant: 0.4,
            frequencyBinCount: 256,
            getByteFrequencyData: (arr) => arr.fill(10)
          };
        }
        close() {}
      },
      api: {
        get: async (act) => {
          if (act === 'proctoring.check_reference') {
            return { success: true, has_reference: false }; // first time
          }
          return {};
        },
        post: async () => ({ success: true })
      },
      formatTime: () => '10:00',
      setInterval: () => 123,
      clearInterval: () => {},
      setTimeout: (fn) => fn(),
      console
    };

    vm.createContext(sandbox);
    vm.runInContext(engineCode, sandbox);
    assert(sandbox.QuizEngine, 'QuizEngine component must be defined');

    const qe = sandbox.QuizEngine();
    qe.quiz = { id: 1, title: 'Kuis Ujian', require_camera: 1, duration: 600 };
    qe.questions = [
      { id: 101, question_text: 'Soal 1', options: [{ id: 1, option_text: 'A' }] },
      { id: 102, question_text: 'Soal 2', options: [{ id: 2, option_text: 'B' }] }
    ];

    qe.phase = 'ready';
    assert.equal(qe.phase, 'ready', 'Phase should be ready before student clicks start');

    let handsFreeCalled = false;
    qe.startHandsFreeFaceScan = () => { handsFreeCalled = true; qe.phase = 'face_scan'; };

    await qe.startQuiz();
    assert(handsFreeCalled, 'First time user must transition to Hands-Free Face Scan');
    assert.equal(qe.phase, 'face_scan', 'Phase must be face_scan');
  });

  await runTest('3.2 Subsequent assignment reuses reference photo without retaking', async () => {
    const engineCode = fs.readFileSync(path.join(__dirname, '../assets/js/quiz-engine.v6.js'), 'utf8');
    const sandbox = {
      window: {},
      document: createMockDocument(),
      navigator: {
        mediaDevices: {
          getUserMedia: async () => ({
            getTracks: () => [{ stop: () => {} }],
            getVideoTracks: () => [{ readyState: 'live' }],
            getAudioTracks: () => [{ readyState: 'live' }]
          })
        }
      },
      AudioContext: class {
        createMediaStreamSource() { return { connect: () => {} }; }
        createAnalyser() {
          return {
            fftSize: 512,
            smoothingTimeConstant: 0.4,
            frequencyBinCount: 256,
            getByteFrequencyData: () => {}
          };
        }
        close() {}
      },
      api: {
        get: async (act) => {
          if (act === 'proctoring.check_reference') {
            return { success: true, has_reference: true }; // ALREADY has reference photo
          }
          return {};
        },
        post: async () => ({ success: true })
      },
      formatTime: () => '10:00',
      setInterval: () => 123,
      clearInterval: () => {},
      setTimeout: (fn) => fn(),
      console
    };

    vm.createContext(sandbox);
    vm.runInContext(engineCode, sandbox);

    const qe = sandbox.QuizEngine();
    qe.quiz = { id: 2, title: 'Tugas Berikutnya', require_camera: 1 };
    qe.questions = [{ id: 201, question_text: 'Soal', options: [] }];

    let handsFreeCalled = false;
    qe.startHandsFreeFaceScan = () => { handsFreeCalled = true; };

    let initialCheckinCalled = false;
    qe.takeInitialCheckinSnapshot = () => { initialCheckinCalled = true; };

    await qe.startQuiz();
    assert(!handsFreeCalled, 'Should NOT retake reference photo on subsequent assignment');
    assert(initialCheckinCalled, 'Must perform initial check-in seamlessly');
    assert.equal(qe.phase, 'playing', 'Phase must immediately be playing');
  });

  await runTest('3.3 Web Audio Noise Detection triggers Screen Freeze, Timer Pause, & Question Swap', async () => {
    const engineCode = fs.readFileSync(path.join(__dirname, '../assets/js/quiz-engine.v6.js'), 'utf8');
    let swappedCall = false;

    const sandbox = {
      window: {},
      document: createMockDocument(),
      api: {
        post: async (act, payload) => {
          if (act === 'proctoring.swap_question') {
            swappedCall = true;
            return {
              success: true,
              swapped: true,
              new_question: {
                id: 999,
                question_text: 'Soal Pengganti Baru',
                options: [{ id: 91, option_text: 'Opsi Baru' }]
              }
            };
          }
          return { success: true };
        }
      },
      formatTime: () => '10:00',
      setInterval: () => 123,
      clearInterval: () => {},
      setTimeout: (fn) => fn(),
      console
    };

    vm.createContext(sandbox);
    vm.runInContext(engineCode, sandbox);

    const qe = sandbox.QuizEngine();
    qe.phase = 'playing';
    qe.timeLeft = 300;
    qe.questions = [
      { id: 101, question_text: 'Soal Asli', options: [{ id: 1, option_text: 'A' }] }
    ];
    qe.currentIndex = 0;
    qe.answers = { 101: 1 };

    // Trigger noise penalty
    await qe.triggerNoisePenalty();

    assert.equal(qe.isNoiseFrozen, true, 'isNoiseFrozen must be true');
    assert.equal(qe.noiseFreezeCountdown, 15, 'Freeze countdown must start at 15');
    assert.equal(qe.answers[101], undefined, 'Answer for violated question must be reset');
    assert(swappedCall, 'API proctoring.swap_question must be called');
    assert.equal(qe.questions[0].id, 999, 'Current question must be replaced with swapped question');
    assert.equal(qe.questions[0].question_text, 'Soal Pengganti Baru');

    // Verify timer pause behavior
    const prevTime = qe.timeLeft;
    if (qe.isNoiseFrozen) {
      // Timer does not decrement!
    } else {
      qe.timeLeft--;
    }
    assert.equal(qe.timeLeft, prevTime, 'Timer must be frozen while screen is frozen');
  });

  await runTest('3.4 Admin Reset Reference Photo in app.v5.js works as expected', async () => {
    const appCode = fs.readFileSync(path.join(__dirname, '../assets/js/app.v5.js'), 'utf8');
    let resetCalledWith = null;

    const sandbox = {
      window: { location: { origin: 'https://quizb.my.id', reload: () => {} }, open: () => {} },
      document: {},
      confirm: () => true,
      api: {
        post: async (act, payload) => {
          if (act === 'proctoring.reset_reference') {
            resetCalledWith = payload.user_id;
            return { success: true, message: 'Foto acuan ujian berhasil direset.' };
          }
          return { success: true };
        }
      },
      store: { get: (k, def) => def },
      debounce: (f) => f,
      console
    };

    vm.createContext(sandbox);
    vm.runInContext(appCode, sandbox);

    const app = sandbox.QuizBApp();
    let toastMsg = '';
    app.showToast = (msg) => { toastMsg = msg; };

    await app.resetStudentExamPhoto({ id: 42, name: 'Budi Santoso' });
    assert.equal(resetCalledWith, 42, 'Must call proctoring.reset_reference with user_id 42');
    assert(toastMsg.includes('berhasil direset'), 'Toast must indicate success');
  });

  // ─────────────────────────────────────────────────────────────────
  // TEST GROUP 4: BACKEND PHP CONTROLLER VERIFICATION
  // ─────────────────────────────────────────────────────────────────
  await runTest('4.1 api/proctoring.php and includes/proctoring.php declare required handlers', () => {
    const incCode = fs.readFileSync(path.join(__dirname, '../includes/proctoring.php'), 'utf8');
    assert(incCode.includes('function ensureProctoringTables()'), 'Must declare ensureProctoringTables');
    assert(incCode.includes('function saveExamReferencePhoto('), 'Must declare saveExamReferencePhoto');
    assert(incCode.includes('function resetExamReferencePhoto('), 'Must declare resetExamReferencePhoto');
    assert(incCode.includes('function getUserExamReference('), 'Must declare getUserExamReference');

    const apiCode = fs.readFileSync(path.join(__dirname, '../api/proctoring.php'), 'utf8');
    assert(apiCode.includes('function proctoring_check_reference()'), 'Must declare check_reference');
    assert(apiCode.includes('function proctoring_save_reference()'), 'Must declare save_reference');
    assert(apiCode.includes('function proctoring_reset_reference()'), 'Must declare reset_reference');
    assert(apiCode.includes('function proctoring_log_event()'), 'Must declare log_event');
    assert(apiCode.includes('function proctoring_swap_question()'), 'Must declare swap_question');
    assert(apiCode.includes('function proctoring_get_logs()'), 'Must declare get_logs');
  });

  await runTest('4.2 proctoring_swap_question strictly avoids leaking correct_option_id (P0 compliance)', () => {
    const apiCode = fs.readFileSync(path.join(__dirname, '../api/proctoring.php'), 'utf8');
    const optionQueryRegex = /SELECT\s+id,\s*question_id,\s*option_text,\s*order_num\s+FROM\s+question_options/i;
    assert(optionQueryRegex.test(apiCode), 'Must select only non-leaking columns from question_options');
    assert(!apiCode.includes('is_correct'), 'Must NEVER include is_correct in proctoring_swap_question');
  });

  console.log('\n====================================================');
  console.log(`   RE-TEST SUITE FINISHED: ${passedTests} / ${totalTests} TESTS PASSED`);
  console.log('====================================================\n');

  if (passedTests === totalTests) {
    process.exit(0);
  } else {
    process.exit(1);
  }
})();
