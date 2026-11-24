/**
 * QuizB AI Proctoring — Hands-Free Face Verifier & Real-Time Biometric Guide
 * Berbasis Pico ML Decision Tree Classifier & Facial Heuristic Analyzer.
 * Zero-Click: Mengarahkan posisi wajah mahasiswa secara dinamis dan otomatis menangkap foto
 * setelah posisi stabil selama 2 detik tanpa perlu klik tombol apapun.
 */
(function(window) {
    'use strict';

    // ── 1. Embedded Pico Face Detection Engine ──────────────────────
    var pico = {};

    pico.unpack_cascade = function(bytes) {
        var dview = new DataView(new ArrayBuffer(4));
        var p = 8;
        dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
        var tdepth = dview.getInt32(0, true);
        p = p + 4;
        dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
        var ntrees = dview.getInt32(0, true);
        p = p + 4;
        var tcodes_ls = [];
        var tpreds_ls = [];
        var thresh_ls = [];
        for (var t = 0; t < ntrees; ++t) {
            Array.prototype.push.apply(tcodes_ls, [0, 0, 0, 0]);
            Array.prototype.push.apply(tcodes_ls, bytes.slice(p, p + 4 * Math.pow(2, tdepth) - 4));
            p = p + 4 * Math.pow(2, tdepth) - 4;
            for (var i = 0; i < Math.pow(2, tdepth); ++i) {
                dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
                tpreds_ls.push(dview.getFloat32(0, true));
                p = p + 4;
            }
            dview.setUint8(0, bytes[p+0]); dview.setUint8(1, bytes[p+1]); dview.setUint8(2, bytes[p+2]); dview.setUint8(3, bytes[p+3]);
            thresh_ls.push(dview.getFloat32(0, true));
            p = p + 4;
        }
        var tcodes = new Int8Array(tcodes_ls);
        var tpreds = new Float32Array(tpreds_ls);
        var thresh = new Float32Array(thresh_ls);

        return function classify_region(r, c, s, pixels, ldim) {
            r = 256 * r;
            c = 256 * c;
            var root = 0;
            var o = 0.0;
            var pow2tdepth = Math.pow(2, tdepth) >> 0;

            for (var i = 0; i < ntrees; ++i) {
                var idx = 1;
                for (var j = 0; j < tdepth; ++j) {
                    idx = 2 * idx + (pixels[((r + tcodes[root + 4 * idx + 0] * s) >> 8) * ldim + ((c + tcodes[root + 4 * idx + 1] * s) >> 8)] <= pixels[((r + tcodes[root + 4 * idx + 2] * s) >> 8) * ldim + ((c + tcodes[root + 4 * idx + 3] * s) >> 8)]);
                }
                o = o + tpreds[pow2tdepth * i + idx - pow2tdepth];
                if (o <= thresh[i]) return -1;
                root += 4 * pow2tdepth;
            }
            return o - thresh[ntrees - 1];
        };
    };

    pico.run_cascade = function(image, classify_region, params) {
        var pixels = image.pixels;
        var nrows = image.nrows;
        var ncols = image.ncols;
        var ldim = image.ldim;
        var shiftfactor = params.shiftfactor;
        var minsize = params.minsize;
        var maxsize = params.maxsize;
        var scalefactor = params.scalefactor;
        var scale = minsize;
        var detections = [];

        while (scale <= maxsize) {
            var step = Math.max(shiftfactor * scale, 1) >> 0;
            var offset = (scale / 2 + 1) >> 0;
            for (var r = offset; r <= nrows - offset; r += step) {
                for (var c = offset; c <= ncols - offset; c += step) {
                    var q = classify_region(r, c, scale, pixels, ldim);
                    if (q > 0.0) {
                        detections.push([r, c, scale, q]);
                    }
                }
            }
            scale = scale * scalefactor;
        }
        return detections;
    };

    pico.cluster_detections = function(dets, iouthreshold) {
        dets = dets.sort(function(a, b) { return b[3] - a[3]; });
        function calculate_iou(det1, det2) {
            var r1 = det1[0], c1 = det1[1], s1 = det1[2];
            var r2 = det2[0], c2 = det2[1], s2 = det2[2];
            var overr = Math.max(0, Math.min(r1 + s1 / 2, r2 + s2 / 2) - Math.max(r1 - s1 / 2, r2 - s2 / 2));
            var overc = Math.max(0, Math.min(c1 + s1 / 2, c2 + s2 / 2) - Math.max(c1 - s1 / 2, c2 - s2 / 2));
            return (overr * overc) / (s1 * s1 + s2 * s2 - overr * overc);
        }
        var assignments = new Array(dets.length).fill(0);
        var clusters = [];
        for (var i = 0; i < dets.length; ++i) {
            if (assignments[i] === 0) {
                var r = 0.0, c = 0.0, s = 0.0, q = 0.0, n = 0;
                for (var j = i; j < dets.length; ++j) {
                    if (calculate_iou(dets[i], dets[j]) > iouthreshold) {
                        assignments[j] = 1;
                        r += dets[j][0];
                        c += dets[j][1];
                        s += dets[j][2];
                        q += dets[j][3];
                        n += 1;
                    }
                }
                clusters.push([r / n, c / n, s / n, q]);
            }
        }
        return clusters;
    };

    // ── 2. QuizFaceVerifier Engine ──────────────────────────────────
    var QuizFaceVerifier = {
        cascadeClassifier: null,
        isCascadeLoading: false,
        samplingCanvas: null,
        samplingCtx: null,

        /**
         * Load pico facefinder cascade
         */
        initCascade: function() {
            if (this.cascadeClassifier) return Promise.resolve(this.cascadeClassifier);
            if (this._cascadePromise) return this._cascadePromise;
            this.isCascadeLoading = true;
            this._cascadePromise = fetch('assets/js/facefinder.bin', {signal: AbortSignal.timeout(15000)})
                .then(res => { if (!res.ok) throw Error('Modul deteksi kamera gagal dimuat.'); return res.arrayBuffer(); })
                .then(buf => { if (buf.byteLength < 200000) throw Error('Modul deteksi kamera tidak valid.'); this.cascadeClassifier = pico.unpack_cascade(new Int8Array(buf)); return this.cascadeClassifier; })
                .finally(() => { this.isCascadeLoading = false; this._cascadePromise = null; });
            return this._cascadePromise;
        },

        /**
         * Analisis satu frame video secara langsung
         * @param {HTMLVideoElement} videoEl
         * @param {Object} [options]
         * @param {boolean} [options.requireCentered=true] Terapkan panduan posisi tengah
         * @returns {Object} result: { ready, label, alertType, color, score, box }
         */
        evaluateFrame: function(videoEl, { requireCentered = true } = {}) {
            var self = this;
            if (!videoEl || videoEl.videoWidth === 0 || videoEl.readyState < 2) {
                return { ready: false, label: 'Menyiapkan kamera...', alertType: 'info' };
            }

            var vw = videoEl.videoWidth;
            var vh = videoEl.videoHeight;

            // Gunakan canvas sampling 240x180 (atau 240x320)
            var sampleW = 240;
            var sampleH = Math.round((sampleW / vw) * vh);

            if (!self.samplingCanvas) {
                self.samplingCanvas = document.createElement('canvas');
            }
            if (self.samplingCanvas.width !== sampleW || self.samplingCanvas.height !== sampleH) {
                self.samplingCanvas.width = sampleW;
                self.samplingCanvas.height = sampleH;
                self.samplingCtx = self.samplingCanvas.getContext('2d', { willReadFrequently: true });
            }

            var ctx = self.samplingCtx;
            ctx.drawImage(videoEl, 0, 0, sampleW, sampleH);

            var imgData;
            try {
                imgData = ctx.getImageData(0, 0, sampleW, sampleH);
            } catch(e) {
                return { ready: false, label: 'Membaca sensor kamera...', alertType: 'info' };
            }

            var pixels = imgData.data;
            var totalPixels = sampleW * sampleH;

            // 1. Cek Pencahayaan (Luminansi Rata-rata)
            var totalLum = 0;
            var gray = new Uint8Array(totalPixels);
            for (var r = 0; r < sampleH; ++r) {
                for (var c = 0; c < sampleW; ++c) {
                    var pIdx = (r * sampleW + c) * 4;
                    var lum = Math.round(0.299 * pixels[pIdx] + 0.587 * pixels[pIdx + 1] + 0.114 * pixels[pIdx + 2]);
                    gray[r * sampleW + c] = lum;
                    totalLum += lum;
                }
            }
            var avgLum = totalLum / totalPixels;

            if (avgLum < 38) {
                return {
                    ready: false,
                    label: '⚠️ Kurang Terang — Silakan cari pencahayaan lebih terang',
                    alertType: 'warning',
                    color: 'amber'
                };
            }
            if (avgLum > 248) {
                return {
                    ready: false,
                    label: '⚠️ Terlalu Silau — Kurangi cahaya langsung ke kamera',
                    alertType: 'warning',
                    color: 'amber'
                };
            }

            // 2. Deteksi Wajah dengan Pico Cascade
            if (!self.cascadeClassifier) {
                return { ready: false, label: 'Memuat modul AI...', alertType: 'info' };
            }

            var image = {
                pixels: gray,
                nrows: sampleH,
                ncols: sampleW,
                ldim: sampleW
            };
            var params = {
                shiftfactor: 0.1,
                minsize: 30,
                maxsize: Math.min(sampleW, sampleH),
                scalefactor: 1.15
            };

            var rawDets = pico.run_cascade(image, self.cascadeClassifier, params);
            var dets = pico.cluster_detections(rawDets, 0.2);
            var confidentFaces = dets.filter(function(d) { return d[3] > 4.5; });

            if (confidentFaces.length === 0) {
                return {
                    ready: false,
                    label: '⚠️ Wajah Tidak Terdeteksi — Menghadaplah ke kamera',
                    alertType: 'danger',
                    color: 'red'
                };
            }

            if (confidentFaces.length > 1) {
                return {
                    ready: false,
                    label: '⚠️ Terdeteksi Lebih dari 1 Orang (' + confidentFaces.length + ' orang) — Ujian wajib perorangan',
                    alertType: 'danger',
                    color: 'red'
                };
            }

            // 1 Wajah Ditemukan — Evaluasi Posisi & Proporsi
            var best = confidentFaces[0];
            var br = best[0]; // row (Y)
            var bc = best[1]; // col (X)
            var bs = best[2]; // size
            var score = best[3];

            if (requireCentered) {
                // Koordinat relatif 0.0 - 1.0 terhadap frame
                var normCenterX = bc / sampleW;
                var normCenterY = br / sampleH;

                // Catatan: Kamera selfie biasanya di-mirror secara horizontal di UI
                // normCenterX < 0.36 artinya di kiri kamera (kanan user saat mirror)
                if (normCenterX < 0.36) {
                    return {
                        ready: false,
                        label: '👉 Posisikan Wajah: Geser sedikit ke Kanan',
                        alertType: 'warning',
                        color: 'amber'
                    };
                }
                if (normCenterX > 0.64) {
                    return {
                        ready: false,
                        label: '👈 Posisikan Wajah: Geser sedikit ke Kiri',
                        alertType: 'warning',
                        color: 'amber'
                    };
                }

                if (normCenterY < 0.28) {
                    return {
                        ready: false,
                        label: '👇 Posisikan Wajah: Geser sedikit ke Bawah',
                        alertType: 'warning',
                        color: 'amber'
                    };
                }
                if (normCenterY > 0.72) {
                    return {
                        ready: false,
                        label: '👆 Posisikan Wajah: Geser sedikit ke Atas',
                        alertType: 'warning',
                        color: 'amber'
                    };
                }
            }

            var normSize = bs / sampleW;
            if (normSize < 0.22) {
                return {
                    ready: false,
                    label: '🔍 Terlalu Jauh — Dekatkan wajah Anda ke kamera',
                    alertType: 'warning',
                    color: 'amber'
                };
            }
            if (normSize > 0.78) {
                return {
                    ready: false,
                    label: '🔍 Terlalu Dekat — Mundurkan sedikit wajah Anda',
                    alertType: 'warning',
                    color: 'amber'
                };
            }

            // Presence/position feedback only. Identity and aligned face comparison
            // are performed by the server; skin colour/eye contrast do not decide access.
            // Semua kriteria untuk pemeriksaan ini terpenuhi.
            return {
                ready: true,
                label: requireCentered ? '✅ Posisi Sempurna! Tahan posisi Anda...' : 'Wajah terdeteksi',
                alertType: 'success',
                color: 'green',
                score: score,
                box: {
                    x: Math.round((bc - bs / 2) * (vw / sampleW)),
                    y: Math.round((br - bs / 2) * (vh / sampleH)),
                    size: Math.round(bs * (vw / sampleW))
                }
            };
        },

        // Legacy callers must not interpret missing/invalid descriptors as a match.
        compareDescriptors: function() { return {match:false, similarity:null, reason:'Verifikasi identitas dilakukan di server'}; },
        compareWithReference: function() { return {match:false, similarity:null, reason:'Verifikasi identitas dilakukan di server'}; },

        /**
         * Tangkap snapshot pasfoto 3:4 portrait (480x640)
         */
        captureSnapshot: function(videoEl) {
            var vw = videoEl.videoWidth || 640;
            var vh = videoEl.videoHeight || 480;

            var canvas = document.createElement('canvas');
            canvas.width = Math.min(vw, 640);
            canvas.height = Math.round(vh * canvas.width / vw);
            var ctx = canvas.getContext('2d');
            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
            return canvas.toDataURL('image/jpeg', 0.85);
        }
    };

    window.QuizFaceVerifier = QuizFaceVerifier;
})(window);
