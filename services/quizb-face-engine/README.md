# QuizB isolated verification worker

Adapted from the existing LPPAI YuNet/SFace worker with its behavior retained except motion tolerance. Includes upstream model licenses; model files and existing Python environment are reused read-only from the VPS, not copied into Git. This is a dedicated process on loopback 8018; LPPAI continues using its original service on 8017. Only `/quizb/health` and `/quizb/verify` proxy routes are added under the existing TLS hostname. LPPAI code, token file, model files and verification thresholds are unchanged.

QuizB turn ratio minimum is 0.08 instead of LPPAI 0.12 (one third less required movement). Return-to-center tolerance is 0.14 instead of 0.10. At least two samples per stage must still satisfy motion; no movement or the wrong direction fails. Identity cosine default remains 0.45 for every valid sample, including while turning. No reduction in identity similarity is applied. These geometry thresholds are engineering settings, not an accuracy percentage, and need validation with consenting students on real devices. Basic motion is not certified PAD/liveness.

The browser mirrors its preview, so raw server `left` must display a rightward arrow and vice versa. Nonce, nine frames, server receipts, final verification and fail-closed behavior are restored from QuizB 1972cd3. The instruction text requested by the user is preserved.

Deployment on the existing VPS: `sudo bash bin/install-quizb-face-vps.sh <commit>`. The installer is specific to the inspected VPS paths and service account. It validates Nginx before graceful reload and starts a separate constrained QuizB systemd unit. It does not restart LPPAI. Shared resources still require capacity testing; this package does not claim a verified concurrent-student capacity.

After the dedicated worker is healthy, set private QuizB config URL to `https://face.lppai.quizb.my.id/quizb`, retaining its existing token. Keep file permission 600. Run `php bin/check-proctoring.php` on hosting, then perform a real camera test. Do not print tokens or participant images to logs/chat.
