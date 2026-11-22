"""YuNet detection + SFace embeddings. No images or embeddings are persisted."""
import base64
import hashlib
import io
import os
import statistics
import threading
from pathlib import Path

# Bound native threads before importing the model libraries.
os.environ['OPENBLAS_NUM_THREADS']='1'
os.environ['OMP_NUM_THREADS']='1'
os.environ['MKL_NUM_THREADS']='1'

import cv2
import numpy as np
from PIL import Image, ImageOps


class FaceError(ValueError):
    def __init__(self, message, fatal=False):
        super().__init__(message)
        self.fatal = fatal


def decode_image(payload: str):
    if not isinstance(payload, str) or len(payload) > 12_000_000:
        raise FaceError('Ukuran gambar tidak valid.')
    if payload.startswith('data:image/jpeg;base64,'):
        payload = payload.split(',', 1)[1]
    try:
        raw = base64.b64decode(payload, validate=True)
        with Image.open(io.BytesIO(raw)) as source:
            if source.width * source.height > 16_000_000 or min(source.size) < 112:
                raise FaceError('Resolusi foto tidak sesuai. Gunakan foto wajah yang jelas.')
            image = ImageOps.exif_transpose(source).convert('RGB')
            image.thumbnail((960, 960))
            return cv2.cvtColor(np.array(image), cv2.COLOR_RGB2BGR)
    except FaceError:
        raise
    except Exception as error:
        raise FaceError('Gambar tidak dapat dibaca.') from error


def motion_details(groups, direction):
    """Basic randomized head-turn challenge; NOT certified presentation attack detection."""
    def nose_ratio(face):
        eye_mid = (face[4] + face[6]) / 2
        eye_distance = abs(face[4] - face[6])
        if eye_distance < 12:
            raise FaceError('Wajah terlalu kecil. Dekatkan wajah ke kamera.')
        return float((face[8] - eye_mid) / eye_distance)

    baseline = statistics.median(nose_ratio(face) for face in groups[0])
    turns = [nose_ratio(face) - baseline for face in groups[1]]
    required = min(2, len(turns))
    if sum(delta < -0.08 if direction == 'left' else delta > 0.08 for delta in turns) < required:
        side = 'kiri' if direction == 'left' else 'kanan'
        return False, 2, f'Langkah 2: gerakan ke {side} layar belum cukup terbaca. Putar kepala perlahan, bukan hanya menggeser badan, dan tahan mengikuti hitung mundur.'
    returned = [abs(nose_ratio(face) - baseline) < 0.14 for face in groups[2]]
    if sum(returned) < min(2, len(returned)):
        return False, 3, 'Langkah 3: kepala belum kembali ke posisi awal. Hadapkan wajah seperti pada langkah pertama dan tahan hingga hitung mundur selesai.'
    return True, None, ''


def motion_passes(faces, direction):
    size = len(faces) // 3
    groups = [faces[i * size:(i + 1) * size] for i in range(3)]
    return motion_details(groups, direction)[0]


class FaceEngine:
    def __init__(self, model_dir=None, threshold=None):
        model_dir = Path(model_dir or Path(__file__).parent / 'models')
        self.threshold = float(threshold if threshold is not None else os.getenv('FACE_MATCH_THRESHOLD', '0.45'))
        if not 0.363 <= self.threshold <= 0.9:
            raise ValueError('FACE_MATCH_THRESHOLD must be between 0.363 and 0.9')
        self.detector = cv2.FaceDetectorYN.create(str(model_dir / 'yunet.onnx'), '', (320, 320), 0.9, 0.3, 5000)
        self.recognizer = cv2.FaceRecognizerSF.create(str(model_dir / 'sface.onnx'), '')
        cv2.setNumThreads(1)
        self.lock = threading.Lock()

    def describe(self, image):
        height, width = image.shape[:2]
        self.detector.setInputSize((width, height))
        _, faces = self.detector.detect(image)
        if faces is not None and len(faces) > 1:
            raise FaceError('Lebih dari satu wajah terdeteksi. Pastikan hanya Anda berada di kamera.', fatal=True)
        if faces is None or len(faces) != 1:
            raise FaceError('Harus ada tepat satu wajah pada setiap foto, termasuk pasfoto pendaftaran.')
        face = faces[0]
        if min(face[2:4]) < 70:
            raise FaceError('Wajah terlalu kecil. Dekatkan wajah ke kamera atau perbarui pasfoto.')
        x, y, w, h = [int(value) for value in face[:4]]
        crop = image[max(0, y):min(height, y + h), max(0, x):min(width, x + w)]
        if crop.size == 0:
            raise FaceError('Wajah berada di luar bingkai.')
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        if gray.mean() < 35 or gray.mean() > 225 or cv2.Laplacian(gray, cv2.CV_64F).var() < 20:
            raise FaceError('Foto terlalu gelap, terang, atau buram. Perbaiki pencahayaan dan tahan kepala.')
        aligned = self.recognizer.alignCrop(image, face)
        return face, self.recognizer.feature(aligned).copy()

    def verify(self, reference, frames, direction=None):
        result = dict(engine='opencv-sface-v1', match=False, similarity=0.0,
                      status='ERROR', reason='', motion_passed=False)
        if direction not in (None, 'left', 'right') or len(frames) not in ((3, 9) if direction else (1,)):
            return dict(result, reason='Data tantangan tidak valid.')
        if not self.lock.acquire(blocking=False):
            raise BlockingIOError('Engine busy')
        try:
            try:
                _, ref_embedding = self.describe(decode_image(reference))
            except FaceError as error:
                raise FaceError('Pasfoto pendaftaran: ' + str(error)) from error
            images = [decode_image(frame) for frame in frames]
            if direction and len({hashlib.sha256(image.tobytes()).digest() for image in images}) != len(images):
                raise FaceError('Foto tantangan berulang. Ikuti gerakan dari kamera secara langsung.')
            groups = []
            size = len(images) // 3 if direction else 1
            for stage in range(3 if direction else 1):
                valid = []
                last_error = None
                for image in images[stage * size:(stage + 1) * size]:
                    try:
                        valid.append(self.describe(image))
                    except FaceError as error:
                        if error.fatal: raise
                        last_error = error
                if len(valid) < min(2, size):
                    prefix = f'Langkah {stage + 1}: ' if direction else 'Kamera: '
                    raise FaceError(prefix + str(last_error or 'Wajah belum cukup jelas.'))
                groups.append(valid)
            descriptions = [description for group in groups for description in group]
            scores = [float(self.recognizer.match(ref_embedding, embedding, cv2.FaceRecognizerSF_FR_COSINE))
                      for _, embedding in descriptions]
            same_person = all(score >= self.threshold for score in scores)
            # Require all challenge frames to contain the same person as well as the reference.
            consistent = all(float(self.recognizer.match(descriptions[0][1], embedding,
                              cv2.FaceRecognizerSF_FR_COSINE)) >= self.threshold
                             for _, embedding in descriptions[1:])
            score = round(min(scores) * 100, 1)
            if not same_person or not consistent:
                return dict(result, status='MISMATCH', similarity=score,
                            reason='Ciri wajah belum sesuai pasfoto pendaftaran. Hadapkan wajah dengan pencahayaan jelas.')
            motion, step, motion_reason = motion_details([[face for face, _ in group] for group in groups], direction) if direction else (False, None, '')
            if direction and not motion:
                return dict(result, status='MOTION_REQUIRED', similarity=score,
                            failed_step=step, reason=motion_reason)
            return dict(result, match=True, status='MATCH', similarity=score, motion_passed=motion,
                        reason='Ciri wajah sesuai pasfoto pendaftaran. Skor adalah indeks kemiripan, bukan persentase kepastian identitas.')
        except FaceError as error:
            return dict(result, status='NO_FACE', reason=str(error))
        finally:
            self.lock.release()
