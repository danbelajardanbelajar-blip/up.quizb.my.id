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
            var self = this;
            if (self.cascadeClassifier || self.isCascadeLoading) return Promise.resolve(self.cascadeClassifier);
            self.isCascadeLoading = true;

            var cascadeUrl = 'assets/js/facefinder.bin';
            return fetch(cascadeUrl)
                .then(function(res) {
                    if (!res.ok) throw new Error("HTTP " + res.status);
                    return res.arrayBuffer();
                })
                .then(function(buf) {
                    var bytes = new Int8Array(buf);
                    self.cascadeClassifier = pico.unpack_cascade(bytes);
                    self.isCascadeLoading = false;
                    return self.cascadeClassifier;
                })
                .catch(function(err) {
                    self.isCascadeLoading = false;
                    console.warn('[QuizFaceVerifier] Pico cascade load warning:', err);
                    return null;
                });
        },

        /**
         * Analisis satu frame video secara langsung
         * @param {HTMLVideoElement} videoEl
         * @returns {Object} result: { ready, label, alertType, progress, score, faceBox }
         */
        evaluateFrame: function(videoEl) {
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

            // Koordinat relatif 0.0 - 1.0 terhadap frame
            var normCenterX = bc / sampleW;
            var normCenterY = br / sampleH;
            var normSize = bs / sampleW;

            // Catatan: Kamera selfie biasanya di-mirror secara horizontal di UI
            // normCenterX < 0.38 artinya di kiri kamera (kanan user saat mirror)
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

            // 3. Deteksi Masker / Penutup Wajah Bawah
            var lowerFaceCheck = self.checkLowerFaceObstruction(pixels, sampleW, sampleH, br, bc, bs);
            if (lowerFaceCheck.isCovered) {
                return {
                    ready: false,
                    label: '⚠️ Jangan Bermasker — Buka masker atau penutup wajah Anda',
                    alertType: 'danger',
                    color: 'red'
                };
            }

            // 4. Deteksi Keterbukaan Mata (Anti-Blink / Anti-Closed Eyes)
            var eyeCheck = self.checkEyeOpenness(gray, sampleW, sampleH, br, bc, bs);
            if (!eyeCheck.isOpen) {
                return {
                    ready: false,
                    label: '👁️ Mata Terpejam — Buka kedua mata dan menghadap kamera',
                    alertType: 'warning',
                    color: 'amber'
                };
            }

            // Semua kriteria terpenuhi: HIJAU & SIAP AUTO-CAPTURE!
            return {
                ready: true,
                label: '✅ Posisi Sempurna! Tahan posisi Anda...',
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

        /**
         * Memeriksa apakah area mulut/hidung tertutup masker
         */
        checkLowerFaceObstruction: function(pixels, width, height, r, c, s) {
            // Sampling area hidung dan mulut di bawah pusat wajah
            var mouthY1 = Math.max(0, Math.min(height - 1, Math.round(r + 0.18 * s)));
            var mouthY2 = Math.max(0, Math.min(height - 1, Math.round(r + 0.42 * s)));
            var mouthX1 = Math.max(0, Math.min(width - 1, Math.round(c - 0.20 * s)));
            var mouthX2 = Math.max(0, Math.min(width - 1, Math.round(c + 0.20 * s)));

            var skinCount = 0;
            var totalCount = 0;

            for (var y = mouthY1; y <= mouthY2; y += 2) {
                for (var x = mouthX1; x <= mouthX2; x += 2) {
                    var idx = (y * width + x) * 4;
                    var pr = pixels[idx], pg = pixels[idx + 1], pb = pixels[idx + 2];
                    var yVal = 0.299 * pr + 0.587 * pg + 0.114 * pb;
                    var cb = 128 - 0.168736 * pr - 0.331264 * pg + 0.5 * pb;
                    var cr = 128 + 0.5 * pr - 0.418688 * pg - 0.081312 * pb;

                    var isSkin = (cb >= 70 && cb <= 140 && cr >= 125 && cr <= 185 && yVal >= 35 && yVal <= 245);
                    if (isSkin) skinCount++;
                    totalCount++;
                }
            }

            var skinRatio = totalCount > 0 ? (skinCount / totalCount) : 0;
            // Jika area bibir/hidung memiliki persentase warna kulit < 15%, kemungkinan besar tertutup masker/kain
            var isCovered = (skinRatio < 0.15);

            return {
                isCovered: isCovered,
                skinRatio: skinRatio
            };
        },

        /**
         * Memeriksa keterbukaan mata
         */
        checkEyeOpenness: function(pixels, width, height, r, c, s) {
            var eyeDist = Math.round(0.18 * s);
            var leftEyeX = Math.round(c - eyeDist);
            var rightEyeX = Math.round(c + eyeDist);
            var eyeY = Math.round(r - 0.12 * s);

            var radX = Math.max(3, Math.round(s * 0.08));
            var radY = Math.max(2, Math.round(s * 0.05));

            function inspectRegion(cx, cy) {
                var minVal = 255, maxVal = 0;
                for (var iy = Math.max(0, cy - radY); iy <= Math.min(height - 1, cy + radY); iy++) {
                    for (var ix = Math.max(0, cx - radX); ix <= Math.min(width - 1, cx + radX); ix++) {
                        var val = pixels[iy * width + ix];
                        if (val < minVal) minVal = val;
                        if (val > maxVal) maxVal = val;
                    }
                }
                return (maxVal - minVal);
            }

            var contrastL = inspectRegion(leftEyeX, eyeY);
            var contrastR = inspectRegion(rightEyeX, eyeY);

            // Kontras mata terbuka biasanya >= 24 antara pupil dan sklera
            var isOpen = (contrastL >= 20 || contrastR >= 20);

            return {
                isOpen: isOpen,
                contrastL: contrastL,
                contrastR: contrastR
            };
        },

        _referenceDescriptor: null,

        setReferenceDescriptor: function(desc) {
            this._referenceDescriptor = desc;
        },

        getReferenceDescriptor: function() {
            return this._referenceDescriptor;
        },

        /**
         * Ekstrak biometric face descriptor dari elemen video/canvas/image
         */
        extractFaceDescriptor: function(sourceEl, box) {
            var sw = (sourceEl && (sourceEl.videoWidth || sourceEl.naturalWidth || sourceEl.width)) || 640;
            var sh = (sourceEl && (sourceEl.videoHeight || sourceEl.naturalHeight || sourceEl.height)) || 480;

            var bx = box ? box.x : Math.round(sw * 0.25);
            var by = box ? box.y : Math.round(sh * 0.20);
            var bs = box ? box.size : Math.round(Math.min(sw, sh) * 0.55);

            var pad = Math.round(bs * 0.15);
            var cropX = Math.max(0, bx - pad);
            var cropY = Math.max(0, by - pad);
            var cropW = Math.min(sw - cropX, bs + pad * 2);
            var cropH = Math.min(sh - cropY, bs + pad * 2);

            var normSize = 96;
            var cvs = (typeof document !== 'undefined' && document.createElement) ? document.createElement('canvas') : null;
            if (!cvs || typeof cvs.getContext !== 'function') {
                return {
                    lbpHist: new Array(1024).fill(0.01),
                    bandGradients: [10, 15, 12, 8],
                    skinTone: { cb: 128, cr: 140 }
                };
            }

            cvs.width = normSize;
            cvs.height = normSize;
            var ctx = cvs.getContext('2d');
            if (sourceEl) {
                try {
                    ctx.drawImage(sourceEl, cropX, cropY, cropW, cropH, 0, 0, normSize, normSize);
                } catch(e) {}
            }

            var imgData = ctx.getImageData(0, 0, normSize, normSize);
            var px = imgData ? imgData.data : [];
            if (!px || px.length === 0) {
                return {
                    lbpHist: new Array(1024).fill(0.01),
                    bandGradients: [10, 15, 12, 8],
                    skinTone: { cb: 128, cr: 140 }
                };
            }

            // 1. Grayscale & Min/Max Luminance Normalization
            var gray = new Uint8Array(normSize * normSize);
            var minG = 255, maxG = 0;
            var sumCb = 0, sumCr = 0, skinCount = 0;

            for (var i = 0, p = 0; i < px.length; i += 4, p++) {
                var r = px[i], g = px[i+1], b = px[i+2];
                var yVal = (0.299 * r + 0.587 * g + 0.114 * b) >> 0;
                gray[p] = yVal;
                if (yVal < minG) minG = yVal;
                if (yVal > maxG) maxG = yVal;

                var row = (p / normSize) >> 0;
                var col = p % normSize;
                if (row >= 24 && row <= 72 && col >= 24 && col <= 72) {
                    var cb = (-0.1687 * r - 0.3313 * g + 0.5000 * b + 128);
                    var cr = ( 0.5000 * r - 0.4187 * g - 0.0813 * b + 128);
                    sumCb += cb;
                    sumCr += cr;
                    skinCount++;
                }
            }

            var range = Math.max(1, maxG - minG);
            for (var j = 0; j < gray.length; j++) {
                gray[j] = Math.round(((gray[j] - minG) / range) * 255);
            }

            // 2. Spatial Multi-Block Local Binary Patterns (LBP)
            var numBlocks = 8;
            var blockSize = normSize / numBlocks;
            var numBins = 16;
            var lbpHist = new Float32Array(numBlocks * numBlocks * numBins);

            for (var byIdx = 0; byIdx < numBlocks; byIdx++) {
                for (var bxIdx = 0; bxIdx < numBlocks; bxIdx++) {
                    var blockOffset = (byIdx * numBlocks + bxIdx) * numBins;
                    var startY = byIdx * blockSize;
                    var endY = startY + blockSize;
                    var startX = bxIdx * blockSize;
                    var endX = startX + blockSize;

                    var count = 0;
                    for (var y = startY + 1; y < endY - 1; y++) {
                        for (var x = startX + 1; x < endX - 1; x++) {
                            var center = gray[y * normSize + x];
                            var pattern = 0;
                            if (gray[(y-1) * normSize + (x-1)] >= center) pattern |= 1;
                            if (gray[(y-1) * normSize + x]     >= center) pattern |= 2;
                            if (gray[(y-1) * normSize + (x+1)] >= center) pattern |= 4;
                            if (gray[y * normSize + (x+1)]     >= center) pattern |= 8;
                            if (gray[(y+1) * normSize + (x+1)] >= center) pattern |= 16;
                            if (gray[(y+1) * normSize + x]     >= center) pattern |= 32;
                            if (gray[(y+1) * normSize + (x-1)] >= center) pattern |= 64;
                            if (gray[y * normSize + (x-1)]     >= center) pattern |= 128;

                            var bin = pattern >> 4;
                            lbpHist[blockOffset + bin]++;
                            count++;
                        }
                    }
                    if (count > 0) {
                        for (var binIdx = 0; binIdx < numBins; binIdx++) {
                            lbpHist[blockOffset + binIdx] /= count;
                        }
                    }
                }
            }

            // 3. Vertical Gradient Topology (4 bands: dahi, mata, hidung, mulut)
            var bandGradients = new Float32Array(4);
            var bandH = normSize / 4;
            for (var band = 0; band < 4; band++) {
                var gSum = 0;
                var gCount = 0;
                for (var gy = band * bandH; gy < (band + 1) * bandH - 1; gy++) {
                    for (var gx = 1; gx < normSize - 1; gx++) {
                        var dx = gray[gy * normSize + (gx + 1)] - gray[gy * normSize + (gx - 1)];
                        var dy = gray[(gy + 1) * normSize + gx] - gray[(gy - 1) * normSize + gx];
                        gSum += Math.sqrt(dx * dx + dy * dy);
                        gCount++;
                    }
                }
                bandGradients[band] = gCount > 0 ? (gSum / gCount) : 0;
            }

            return {
                lbpHist: Array.from(lbpHist),
                bandGradients: Array.from(bandGradients),
                skinTone: {
                    cb: skinCount > 0 ? (sumCb / skinCount) : 128,
                    cr: skinCount > 0 ? (sumCr / skinCount) : 128
                }
            };
        },

        /**
         * Bandingkan dua deskriptor wajah biometrik
         */
        compareDescriptors: function(descA, descB) {
            if (!descA || !descB || !descA.lbpHist || !descB.lbpHist) {
                return { match: false, similarity: 0, reason: 'Foto acuan belum terpasang' };
            }

            // 1. Chi-Square Distance pada LBP Multi-Block Histogram
            var hA = descA.lbpHist;
            var hB = descB.lbpHist;
            var len = Math.min(hA.length, hB.length);
            var chiSq = 0.0;

            for (var i = 0; i < len; i++) {
                var a = hA[i];
                var b = hB[i];
                var denom = a + b;
                if (denom > 1e-6) {
                    var diff = a - b;
                    chiSq += (diff * diff) / denom;
                }
            }

            var lbpSim = Math.max(0, Math.min(100, Math.round(100 - (chiSq / 15.0) * 55.0)));

            // 2. Gradient Distribution Difference
            var gA = descA.bandGradients || [0,0,0,0];
            var gB = descB.bandGradients || [0,0,0,0];
            var diffSum = 0, totalSum = 0;
            for (var k = 0; k < 4; k++) {
                diffSum += Math.abs((gA[k] || 0) - (gB[k] || 0));
                totalSum += Math.abs(gA[k] || 0) + Math.abs(gB[k] || 0);
            }
            var gradSim = totalSum > 0 ? Math.max(0, Math.min(100, Math.round((1 - diffSum / totalSum) * 100))) : 50;

            // 3. Skin Tone Distance
            var cbDiff = ((descA.skinTone && descA.skinTone.cb) || 128) - ((descB.skinTone && descB.skinTone.cb) || 128);
            var crDiff = ((descA.skinTone && descA.skinTone.cr) || 128) - ((descB.skinTone && descB.skinTone.cr) || 128);
            var colorDist = Math.sqrt(cbDiff * cbDiff + crDiff * crDiff);
            var colorSim = Math.max(0, Math.min(100, Math.round(100 - (colorDist / 25.0) * 50.0)));

            var finalScore = Math.round(0.60 * lbpSim + 0.25 * gradSim + 0.15 * colorSim);
            finalScore = Math.max(0, Math.min(100, finalScore));

            var isMatch = finalScore >= 55;

            return {
                match: isMatch,
                similarity: finalScore,
                reason: isMatch ? 'Ciri wajah sesuai foto acuan peserta' : 'Wajah tidak cocok dengan foto acuan peserta (' + finalScore + '% kemiripan)'
            };
        },

        compareWithReference: function(liveDesc) {
            if (!this._referenceDescriptor) {
                return { match: true, similarity: 100, reason: 'Belum ada acuan yang dimuat' };
            }
            return this.compareDescriptors(this._referenceDescriptor, liveDesc);
        },

        loadReferenceFromDataUrl: function(dataUrl) {
            var self = this;
            return new Promise(function(resolve) {
                if (!dataUrl) {
                    resolve(null);
                    return;
                }
                if (typeof Image === 'undefined') {
                    self._referenceDescriptor = {
                        lbpHist: new Array(1024).fill(0.01),
                        bandGradients: [10, 15, 12, 8],
                        skinTone: { cb: 128, cr: 140 }
                    };
                    resolve(self._referenceDescriptor);
                    return;
                }
                var img = new Image();
                img.onload = function() {
                    try {
                        var desc = self.extractFaceDescriptor(img);
                        self._referenceDescriptor = desc;
                        resolve(desc);
                    } catch (e) {
                        console.warn('[QuizFaceVerifier] Error extracting ref descriptor:', e);
                        resolve(null);
                    }
                };
                img.onerror = function() {
                    resolve(null);
                };
                img.src = dataUrl;
            });
        },

        /**
         * Tangkap snapshot pasfoto 3:4 portrait (480x640)
         */
        captureSnapshot: function(videoEl) {
            var vw = videoEl.videoWidth || 640;
            var vh = videoEl.videoHeight || 480;

            var targetRatio = 3 / 4;
            var cropW = vw;
            var cropH = vh;
            var startX = 0;
            var startY = 0;

            if (vw / vh > targetRatio) {
                cropW = Math.round(vh * targetRatio);
                startX = Math.round((vw - cropW) / 2);
            } else {
                cropH = Math.round(vw / targetRatio);
                startY = Math.round((vh - cropH) / 2);
            }

            var canvas = document.createElement('canvas');
            canvas.width = 480;
            canvas.height = 640;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(videoEl, startX, startY, cropW, cropH, 0, 0, canvas.width, canvas.height);
            return canvas.toDataURL('image/jpeg', 0.85);
        }
    };

    window.QuizFaceVerifier = QuizFaceVerifier;
})(window);
